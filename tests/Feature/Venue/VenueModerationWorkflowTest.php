<?php

namespace Tests\Feature\Venue;

use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Moderation\Domain\Enums\ModerationRequestStatusEnum;
use App\Modules\Moderation\Domain\Enums\ModerationTypeEnum;
use App\Modules\Moderation\Domain\Models\ModerationRequest;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Enums\VenueTypeEnum;
use App\Modules\Venue\Domain\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VenueModerationWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_cannot_edit_or_save_venue_while_moderation_is_pending(): void
    {
        $owner = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $venue = Venue::factory()->create([
            'created_by_actor_id' => app(CurrentActorResolver::class)->resolve($owner, null)->id,
            'name' => 'Площадка на проверке',
            'status' => VenueStatusEnum::UNCONFIRMED,
        ]);

        $this->actingAs($owner)
            ->post(route('account.venues.moderation.submit', $venue->alias))
            ->assertRedirect(route('account.venues.status', $venue->alias));

        $this->actingAs($owner)
            ->get(route('account.venues.edit', $venue->alias))
            ->assertOk()
            ->assertSee('Площадка находится на модерации')
            ->assertSee('Дождитесь результата модерации')
            ->assertSee('fieldset class="venue-form__fieldset" disabled', false)
            ->assertSee('type="submit" class="btn btn--primary btn--sm" disabled', false)
            ->assertDontSee('Добавить фотографию');

        $this->actingAs($owner)
            ->put(route('account.venues.update', $venue->alias), [
                'name' => 'Подменённое название',
                'type' => $venue->type->value,
            ])
            ->assertRedirect(route('account.venues.edit', $venue->alias))
            ->assertSessionHas('error', 'Площадка находится на модерации. Дождитесь решения перед редактированием.');

        $this->assertSame('Площадка на проверке', $venue->refresh()->name);
    }

    public function test_owner_can_edit_venue_again_after_moderation_rejection(): void
    {
        $owner = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $admin = User::factory()->create([
            'status' => UserStatusEnum::CONFIRMED,
            'system_role' => UserSystemRoleEnum::ADMIN,
        ]);
        $venue = Venue::factory()->create([
            'created_by_actor_id' => app(CurrentActorResolver::class)->resolve($owner, null)->id,
            'status' => VenueStatusEnum::UNCONFIRMED,
        ]);

        $this->actingAs($owner)->post(route('account.venues.moderation.submit', $venue->alias));
        $request = ModerationRequest::query()->firstOrFail();

        $this->actingAs($admin)
            ->post(route('admin.venues.moderation.reject', $request), ['message' => 'Дополните описание.'])
            ->assertRedirect(route('admin.venues'));

        $this->actingAs($owner)
            ->get(route('account.venues.edit', $venue->alias))
            ->assertOk();
    }

    public function test_owner_can_submit_venue_to_moderation_and_see_rejection_reason(): void
    {
        $owner = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $admin = User::factory()->create([
            'status' => UserStatusEnum::CONFIRMED,
            'system_role' => UserSystemRoleEnum::ADMIN,
        ]);
        $venue = Venue::factory()->create([
            'created_by_actor_id' => app(CurrentActorResolver::class)->resolve($owner, null)->id,
            'name' => 'На Дубнинской',
            'alias' => 'na-dubninskoi',
            'status' => VenueStatusEnum::UNCONFIRMED,
            'type' => VenueTypeEnum::STREET_COURT,
        ]);

        $this
            ->actingAs($owner)
            ->get(route('account.venues.status', $venue->alias))
            ->assertOk()
            ->assertSee('Отправить на модерацию');

        $this
            ->actingAs($owner)
            ->get(route('account.venues.edit', $venue->alias))
            ->assertOk()
            ->assertSee('Внутренняя навигация площадки')
            ->assertSee('Обзор')
            ->assertSee('Редактировать')
            ->assertSee('Модерация')
            ->assertDontSee('Комментарий для модератора')
            ->assertSee('Отправить на модерацию');

        $this
            ->actingAs($owner)
            ->post(route('account.venues.moderation.submit', $venue->alias), [
                'message' => 'Проверьте, пожалуйста.',
            ])
            ->assertRedirect(route('account.venues.status', $venue->alias));

        $request = ModerationRequest::query()->firstOrFail();

        Venue::factory()->create(['name' => 'Площадка без заявки']);

        $this->assertSame(ModerationRequestStatusEnum::PENDING, $request->status);

        $this
            ->actingAs($admin)
            ->get(route('admin.venues'))
            ->assertOk()
            ->assertDontSee('<th>Модерация</th>', false)
            ->assertSee('На рассмотрении')
            ->assertSee('От пользователя')
            ->assertSee('Проверьте, пожалуйста.');

        $this
            ->actingAs($admin)
            ->get(route('admin.venues', ['status' => 'pending_moderation']))
            ->assertOk()
            ->assertSee('На Дубнинской')
            ->assertSee('На рассмотрении')
            ->assertDontSee('Площадка без заявки');

        $this
            ->actingAs($admin)
            ->post(route('admin.venues.moderation.reject', $request), [
                'message' => 'Исправьте опечатку в адресе.',
            ])
            ->assertRedirect(route('admin.venues'));

        $this->assertDatabaseHas('moderation_requests', [
            'id' => $request->id,
            'type' => ModerationTypeEnum::VENUE->value,
            'subject_id' => $venue->id,
            'status' => ModerationRequestStatusEnum::REJECTED->value,
            'reviewed_by_user_id' => $admin->id,
        ]);

        $this
            ->actingAs($admin)
            ->get(route('admin.venues'))
            ->assertOk()
            ->assertSee('От пользователя ('.($owner->username ?? $owner->email ?? 'гость').')')
            ->assertSee('От модератора ('.($admin->username ?? $admin->email ?? 'гость').')');

        $this
            ->actingAs($owner)
            ->get(route('account.venues.status', $venue->alias))
            ->assertOk()
            ->assertSee('Отклонена')
            ->assertSee('История запросов')
            ->assertSee('Запрос №'.$request->id)
            ->assertSee('Вы')
            ->assertSee('Вы ('.($owner->username ?? $owner->email ?? 'гость').')')
            ->assertSee('Проверьте, пожалуйста.')
            ->assertSee('Модератор')
            ->assertSee('Модератор ('.($admin->username ?? $admin->email ?? 'гость').')')
            ->assertSee('Исправьте опечатку в адресе.')
            ->assertSeeInOrder([
                'Исправьте опечатку в адресе.',
                'Проверьте, пожалуйста.',
            ]);

        $this
            ->actingAs($admin)
            ->post(route('admin.venues.bulk-block'), [
                'venue_ids' => [$venue->id],
                'message' => 'Повторные некорректные заявки.',
            ])
            ->assertRedirect(route('admin.venues'))
            ->assertSessionHas('success', 'Заблокировано площадок: 1.');

        $this->assertDatabaseHas('venues', [
            'id' => $venue->id,
            'status' => VenueStatusEnum::BLOCKED->value,
            'status_info' => 'Повторные некорректные заявки.',
        ]);
        $this->assertDatabaseHas('moderation_requests', [
            'id' => $request->id,
            'status' => ModerationRequestStatusEnum::REJECTED->value,
        ]);
    }

    public function test_admin_can_block_venue_through_status_without_moderation_request(): void
    {
        $owner = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $admin = User::factory()->create([
            'status' => UserStatusEnum::CONFIRMED,
            'system_role' => UserSystemRoleEnum::ADMIN,
        ]);
        $venue = Venue::factory()->create([
            'created_by_actor_id' => app(CurrentActorResolver::class)->resolve($owner, null)->id,
            'status' => VenueStatusEnum::UNCONFIRMED,
        ]);

        $this
            ->actingAs($admin)
            ->post(route('admin.venues.status.update', $venue), [
                'status' => VenueStatusEnum::BLOCKED->value,
                'message' => 'Повторная отправка без исправлений.',
            ])
            ->assertRedirect(route('admin.venues'));

        $this->assertDatabaseHas('venues', [
            'id' => $venue->id,
            'status' => VenueStatusEnum::BLOCKED->value,
            'status_info' => 'Повторная отправка без исправлений.',
        ]);
        $this->assertDatabaseCount('moderation_requests', 0);

        $this
            ->actingAs($owner)
            ->get(route('account.venues.status', $venue->alias))
            ->assertOk()
            ->assertSee('Площадка заблокирована')
            ->assertDontSee('Отправить на модерацию');
    }

    public function test_confirmed_venue_status_page_does_not_show_moderation_submit_section(): void
    {
        $owner = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $admin = User::factory()->create([
            'status' => UserStatusEnum::CONFIRMED,
            'system_role' => UserSystemRoleEnum::ADMIN,
        ]);
        $venue = Venue::factory()->create([
            'created_by_actor_id' => app(CurrentActorResolver::class)->resolve($owner, null)->id,
            'name' => 'Школа №1794',
            'alias' => 'skola-1794',
            'status' => VenueStatusEnum::UNCONFIRMED,
        ]);

        $this
            ->actingAs($owner)
            ->post(route('account.venues.moderation.submit', $venue->alias))
            ->assertRedirect(route('account.venues.status', $venue->alias));

        $request = ModerationRequest::query()->firstOrFail();

        $this
            ->actingAs($admin)
            ->post(route('admin.venues.moderation.approve', $request), [
                'message' => 'Проверено, всё в порядке.',
            ])
            ->assertRedirect(route('admin.venues'));

        $this->assertDatabaseHas('moderation_requests', [
            'id' => $request->id,
            'status' => ModerationRequestStatusEnum::APPROVED->value,
            'reviewed_by_user_id' => $admin->id,
        ]);
        $this->assertDatabaseHas('venues', [
            'id' => $venue->id,
            'status' => VenueStatusEnum::CONFIRMED->value,
            'status_info' => null,
        ]);

        $this
            ->actingAs($owner)
            ->get(route('account.venues.status', $venue->alias))
            ->assertOk()
            ->assertSee('Площадка подтверждена')
            ->assertSee('Проверено, всё в порядке.')
            ->assertSee(route('venues.show', $venue->routeIdentifier()), false)
            ->assertDontSee('Редактировать площадку')
            ->assertDontSee('Комментарий для модератора')
            ->assertDontSee('Отправить на модерацию');

        $this
            ->actingAs($owner)
            ->get(route('account.venues.edit', $venue->alias))
            ->assertOk()
            ->assertSee('Фотографии')
            ->assertSee('Изменения фотографий появятся на странице после модерации.');

        $this
            ->actingAs($owner)
            ->put(route('account.venues.update', $venue->alias), [
                'name' => 'Изменённое название',
                'type' => VenueTypeEnum::STREET_COURT->value,
            ])
            ->assertRedirect(route('account.venues.edit', $venue->routeIdentifier()))
            ->assertSessionHas('status', 'Изменения сохранены в черновик. Отправьте их на модерацию.');

        $this->assertDatabaseHas('venues', [
            'id' => $venue->id,
            'name' => 'Школа №1794',
            'status' => VenueStatusEnum::CONFIRMED->value,
        ]);
    }

    public function test_admin_can_change_blocked_venue_back_to_unconfirmed_but_cannot_unconfirm_confirmed_venue(): void
    {
        $admin = User::factory()->create([
            'status' => UserStatusEnum::CONFIRMED,
            'system_role' => UserSystemRoleEnum::ADMIN,
        ]);
        $blockedVenue = Venue::factory()->create([
            'status' => VenueStatusEnum::BLOCKED,
            'status_info' => 'Спам-заявки.',
        ]);
        $confirmedVenue = Venue::factory()->create([
            'status' => VenueStatusEnum::CONFIRMED,
        ]);
        $unconfirmedVenue = Venue::factory()->create([
            'status' => VenueStatusEnum::UNCONFIRMED,
        ]);

        $this
            ->actingAs($admin)
            ->post(route('admin.venues.status.update', $blockedVenue), [
                'status' => VenueStatusEnum::UNCONFIRMED->value,
            ])
            ->assertRedirect(route('admin.venues'));

        $this->assertDatabaseHas('venues', [
            'id' => $blockedVenue->id,
            'status' => VenueStatusEnum::UNCONFIRMED->value,
            'status_info' => null,
        ]);

        $this
            ->actingAs($admin)
            ->post(route('admin.venues.status.update', $confirmedVenue), [
                'status' => VenueStatusEnum::UNCONFIRMED->value,
            ])
            ->assertRedirect(route('admin.venues'));

        $this->assertDatabaseHas('venues', [
            'id' => $confirmedVenue->id,
            'status' => VenueStatusEnum::CONFIRMED->value,
        ]);

        $this
            ->actingAs($admin)
            ->post(route('admin.venues.status.update', $unconfirmedVenue), [
                'status' => VenueStatusEnum::BLOCKED->value,
                'message' => 'Ручная блокировка.',
            ])
            ->assertRedirect(route('admin.venues'));

        $this->assertDatabaseHas('venues', [
            'id' => $unconfirmedVenue->id,
            'status' => VenueStatusEnum::BLOCKED->value,
            'status_info' => 'Ручная блокировка.',
        ]);

        $this
            ->actingAs($admin)
            ->from(route('admin.venues'))
            ->post(route('admin.venues.status.update', $unconfirmedVenue), [
                'status' => VenueStatusEnum::CONFIRMED->value,
            ])
            ->assertRedirect(route('admin.venues'))
            ->assertSessionHasErrors('status');

        $this->assertDatabaseHas('venues', [
            'id' => $unconfirmedVenue->id,
            'status' => VenueStatusEnum::BLOCKED->value,
        ]);
    }

    public function test_admin_can_bulk_soft_delete_and_restore_venues(): void
    {
        $admin = User::factory()->create([
            'status' => UserStatusEnum::CONFIRMED,
            'system_role' => UserSystemRoleEnum::ADMIN,
        ]);
        $venue = Venue::factory()->create([
            'name' => 'Площадка для удаления',
        ]);
        $secondVenue = Venue::factory()->create([
            'name' => 'Вторая площадка для удаления',
        ]);

        $this
            ->actingAs($admin)
            ->post(route('admin.venues.bulk-delete'), [
                'venue_ids' => [$venue->id, $secondVenue->id],
            ])
            ->assertRedirect(route('admin.venues'))
            ->assertSessionHas('success', 'Удалено площадок: 2.');

        $this->assertSoftDeleted('venues', ['id' => $venue->id]);
        $this->assertSoftDeleted('venues', ['id' => $secondVenue->id]);

        $this
            ->actingAs($admin)
            ->get(route('admin.venues'))
            ->assertOk()
            ->assertDontSee('Площадка для удаления')
            ->assertDontSee('Вторая площадка для удаления');

        $this
            ->actingAs($admin)
            ->get(route('admin.venues', ['deleted' => 1]))
            ->assertOk()
            ->assertSee('Площадка для удаления')
            ->assertSee('Вторая площадка для удаления')
            ->assertSee('Восстановить')
            ->assertDontSee('Восстановить площадку');

        $this
            ->actingAs($admin)
            ->post(route('admin.venues.bulk-restore'), [
                'venue_ids' => [$venue->id, $secondVenue->id],
            ])
            ->assertRedirect(route('admin.venues', ['deleted' => 1]))
            ->assertSessionHas('success', 'Восстановлено площадок: 2.');

        $this->assertDatabaseHas('venues', [
            'id' => $venue->id,
            'deleted_at' => null,
        ]);
        $this->assertDatabaseHas('venues', [
            'id' => $secondVenue->id,
            'deleted_at' => null,
        ]);
    }

    public function test_edit_page_uses_current_owner_version_when_alias_is_shared_by_duplicates(): void
    {
        $otherOwner = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $owner = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);

        Venue::factory()->create([
            'created_by_actor_id' => app(CurrentActorResolver::class)->resolve($otherOwner, null)->id,
            'name' => 'Чужая версия',
            'alias' => 'shared-venue',
            'status' => VenueStatusEnum::UNCONFIRMED,
        ]);

        $ownVenue = Venue::factory()->create([
            'created_by_actor_id' => app(CurrentActorResolver::class)->resolve($owner, null)->id,
            'name' => 'Моя версия',
            'alias' => 'shared-venue',
            'status' => VenueStatusEnum::UNCONFIRMED,
        ]);

        $this
            ->actingAs($owner)
            ->get(route('account.venues.edit', $ownVenue->alias))
            ->assertOk()
            ->assertSee('Моя версия')
            ->assertDontSee('Чужая версия');
    }
}
