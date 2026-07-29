<?php

namespace Tests\Feature\Admin;

use App\Modules\Audit\Domain\Models\AuditLog;
use App\Modules\Content\Domain\Models\ContentItem;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Portal\Application\Services\OnlineUserPresence;
use App\Modules\Telegram\Domain\Models\TelegramChat;
use App\Modules\Venue\Domain\Enums\VenueDuplicateMatchTypeEnum;
use App\Modules\Venue\Domain\Enums\VenueDuplicateStatusEnum;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Enums\VenueTypeEnum;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\Venue\Domain\Models\VenueDuplicate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_shows_admin_tiles(): void
    {
        $admin = $this->admin();
        TelegramChat::query()->create([
            'telegram_chat_id' => -1001000000001,
            'title' => 'Основной чат',
            'type' => 'supergroup',
            'is_active' => true,
            'publishes_coordination' => true,
        ]);
        TelegramChat::query()->create([
            'telegram_chat_id' => -1001000000002,
            'title' => 'Дополнительный чат',
            'type' => 'supergroup',
            'is_active' => false,
            'publishes_coordination' => false,
        ]);
        ContentItem::query()->create([
            'created_by_user_id' => $admin->id,
            'updated_by_user_id' => $admin->id,
            'type' => 'material',
            'title' => 'Редакционный материал',
            'alias' => 'redaktsionnyy-material',
            'short_description' => 'Краткое описание.',
            'full_description' => 'Полное описание.',
        ]);

        $response = $this
            ->actingAs($admin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Пользователи')
            ->assertSee('Площадки')
            ->assertSee('Мероприятия')
            ->assertSee('Команды')
            ->assertSee('Контент')
            ->assertSee('Аудит')
            ->assertSee('Настройки')
            ->assertSee('Telegram-чаты');

        $tiles = collect($response->viewData('tiles'))->keyBy('label');

        $this->assertSame(1, $tiles['Пользователи']['data']['count']);
        $this->assertSame(0, $tiles['Площадки']['data']['count']);
        $this->assertSame(0, $tiles['Дубли площадок']['data']['count']);
        $this->assertSame(0, $tiles['Мероприятия']['data']['count']);
        $this->assertSame(0, $tiles['Команды']['data']['count']);
        $this->assertSame(1, $tiles['Контент']['data']['count']);
        $this->assertSame(4, $tiles['Настройки']['data']['count']);
        $this->assertSame(2, $tiles['Telegram-чаты']['data']['count']);
    }

    public function test_users_page_filters_by_search(): void
    {
        $admin = $this->admin();
        User::factory()->create([
            'username' => 'visible-user',
            'status' => UserStatusEnum::CONFIRMED,
            'system_role' => UserSystemRoleEnum::USER,
        ]);
        User::factory()->create([
            'username' => 'hidden-user',
            'status' => UserStatusEnum::CONFIRMED,
            'system_role' => UserSystemRoleEnum::USER,
        ]);

        $this
            ->actingAs($admin)
            ->get(route('admin.users', ['search' => 'visible']))
            ->assertOk()
            ->assertSee('visible-user')
            ->assertDontSee('hidden-user');
    }

    public function test_users_page_shows_online_summary_and_presence_for_each_user(): void
    {
        $admin = $this->admin();
        $onlineUser = User::factory()->create([
            'username' => 'online-user',
            'status' => UserStatusEnum::CONFIRMED,
        ]);
        User::factory()->create([
            'username' => 'offline-user',
            'status' => UserStatusEnum::UNCONFIRMED,
        ]);

        app(OnlineUserPresence::class)->touch((int) $onlineUser->id);

        $this
            ->actingAs($admin)
            ->get(route('admin.users'))
            ->assertOk()
            ->assertSee('Онлайн: 2/3')
            ->assertSee('admin-table__presence-cell', false)
            ->assertSee('admin-user-presence--online', false)
            ->assertSee('admin-user-presence--offline', false)
            ->assertSee('Последняя активность:')
            ->assertSee('Не в сети');
    }

    public function test_venues_page_filters_by_status_and_type(): void
    {
        $admin = $this->admin();
        Venue::factory()->create([
            'created_by_actor_id' => $this->actorIdFor($admin),
            'name' => 'Проверяемая площадка',
            'status' => VenueStatusEnum::CONFIRMED,
            'type' => VenueTypeEnum::SPORTS_HALL,
        ]);
        Venue::factory()->create([
            'created_by_actor_id' => $this->actorIdFor($admin),
            'name' => 'Скрытая площадка',
            'status' => VenueStatusEnum::UNCONFIRMED,
            'type' => VenueTypeEnum::STREET_COURT,
        ]);

        $this
            ->actingAs($admin)
            ->get(route('admin.venues', [
                'status' => VenueStatusEnum::CONFIRMED->value,
                'type' => VenueTypeEnum::SPORTS_HALL->value,
            ]))
            ->assertOk()
            ->assertSee('Проверяемая площадка')
            ->assertDontSee('Скрытая площадка');
    }

    public function test_venues_page_shows_duplicate_relationship_summary(): void
    {
        $admin = $this->admin();
        $canonical = Venue::factory()->create([
            'created_by_actor_id' => $this->actorIdFor($admin),
            'name' => 'Главная площадка',
            'status' => VenueStatusEnum::CONFIRMED,
        ]);
        Venue::factory()->create([
            'created_by_actor_id' => $this->actorIdFor($admin),
            'name' => 'Площадка дубль',
            'status' => VenueStatusEnum::UNCONFIRMED,
            'canonical_venue_id' => $canonical->id,
        ]);

        $this
            ->actingAs($admin)
            ->get(route('admin.venues'))
            ->assertOk()
            ->assertSee('Дубли')
            ->assertSee('Главная')
            ->assertSee('Дублей: 1')
            ->assertSee('Дубль #'.$canonical->id)
            ->assertSee('Главная площадка');
    }

    public function test_venues_page_shows_pending_duplicate_candidates_from_worker_table(): void
    {
        $admin = $this->admin();
        $firstVenue = Venue::factory()->create([
            'created_by_actor_id' => $this->actorIdFor($admin),
            'name' => 'Первый кандидат',
            'status' => VenueStatusEnum::UNCONFIRMED,
        ]);
        $secondVenue = Venue::factory()->create([
            'created_by_actor_id' => $this->actorIdFor($admin),
            'name' => 'Второй кандидат',
            'status' => VenueStatusEnum::UNCONFIRMED,
        ]);

        VenueDuplicate::query()->create([
            'venue_id' => $firstVenue->id,
            'duplicate_venue_id' => $secondVenue->id,
            'matched_by' => VenueDuplicateMatchTypeEnum::NAME,
            'status' => VenueDuplicateStatusEnum::PENDING,
            'score' => 70,
        ]);

        $this
            ->actingAs($admin)
            ->get(route('admin.venues'))
            ->assertOk()
            ->assertSee('#'.$firstVenue->id)
            ->assertSee('#'.$secondVenue->id)
            ->assertDontSee('Кандидат дубля')
            ->assertDontSee('Совпадений: 1');
    }

    public function test_audit_page_shows_audit_logs(): void
    {
        $admin = $this->admin();
        $actor = app(CurrentActorResolver::class)->resolve($admin, null);
        $venue = Venue::factory()->create([
            'created_by_actor_id' => $actor->id,
            'name' => 'Площадка для аудита',
        ]);

        AuditLog::query()->create([
            'actor_id' => $actor->id,
            'auditable_type' => Venue::class,
            'auditable_id' => $venue->id,
            'event' => 'updated',
            'old_values' => ['name' => 'Старое название'],
            'new_values' => ['name' => 'Площадка для аудита'],
            'metadata' => ['route' => 'test'],
        ]);

        $this
            ->actingAs($admin)
            ->get(route('admin.audit'))
            ->assertOk()
            ->assertSee('Аудит')
            ->assertSee('Venue')
            ->assertSee('updated')
            ->assertSee('Площадка для аудита');
    }

    private function admin(): User
    {
        return User::factory()->create([
            'status' => UserStatusEnum::CONFIRMED,
            'system_role' => UserSystemRoleEnum::ADMIN,
        ]);
    }

    private function actorIdFor(User $user): int
    {
        return app(CurrentActorResolver::class)->resolve($user, null)->id;
    }
}
