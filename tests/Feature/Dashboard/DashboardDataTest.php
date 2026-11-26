<?php

namespace Tests\Feature\Dashboard;

use App\Modules\Dashboard\Domain\Models\AccountDashboardWidget;
use App\Modules\Event\Domain\Enums\EventTypeEnum;
use App\Modules\Event\Domain\Models\Event as PortalEvent;
use App\Modules\PlayInterest\Domain\Models\VenueSubscription;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\Dashboard\Infrastructure\Broadcasting\DashboardChangedBroadcast;
use App\Modules\Dashboard\Infrastructure\Broadcasting\DashboardWidgetsInvalidatedBroadcast;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

final class DashboardDataTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('themes.active', 'mskba_app');
        app()->forgetInstance(ThemeResolver::class);
        View::replaceNamespace('theme', resource_path('themes/mskba_app/views'));
        Event::fake([DashboardChangedBroadcast::class]);
    }

    public function test_data_endpoint_respects_targeting_visibility_and_unknown_sources(): void
    {
        $user = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $other = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $user->createProfile([]);
        $other->createProfile([]);
        $widget = AccountDashboardWidget::query()->where('kind', 'message_summary')->firstOrFail()->replicate();
        $widget->forceFill([
            'kind' => 'dynamic',
            'source_key' => 'unread_messages',
            'include_user_ids' => [$user->id],
            'exclude_user_ids' => [$other->id],
            'can_hide' => true,
            'enabled' => true,
        ])->save();

        $url = route('account.dashboard.widgets.data', $widget);
        $this->getJson($url)->assertUnauthorized();
        $this->actingAs($other)->getJson($url)->assertNotFound();
        $this->actingAs($user)->getJson($url)->assertOk()
            ->assertJsonPath('widget_id', $widget->id)
            ->assertJsonPath('data.total', 0);

        $this->postJson(route('account.settings.dashboard.update'), ['visible_widget_ids' => []])->assertOk();
        $this->getJson($url)->assertNotFound();

        $this->postJson(route('account.settings.dashboard.update'), ['visible_widget_ids' => [$widget->id]])->assertOk();
        $widget->update(['source_key' => 'unregistered']);
        $this->getJson($url)->assertNotFound();
    }

    public function test_dynamic_interest_content_renders_personal_data_and_clears_on_refresh(): void
    {
        $user = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $other = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $user->createProfile([]);
        $other->createProfile([]);
        $venue = Venue::factory()->create(['status' => VenueStatusEnum::CONFIRMED, 'is_published' => true]);
        VenueSubscription::query()->create(['user_id' => $user->id, 'venue_id' => $venue->id, 'status' => 'active']);
        $event = PortalEvent::factory()->create(['venue_id' => $venue->id, 'type' => EventTypeEnum::TRAINING, 'title' => 'Тестовая тренировка']);
        $widget = AccountDashboardWidget::query()->where('kind', 'message_summary')->firstOrFail()->replicate();
        $widget->forceFill([
            'kind' => 'dynamic', 'source_key' => 'my_interests', 'title' => 'Мои интересы',
            'source_settings' => ['limit' => 5], 'enabled' => true, 'can_hide' => true, 'actions' => [],
        ])->save();

        $this->actingAs($user)->get(route('account'))->assertOk()->assertSee($event->title);
        $html = $this->getJson(route('account.dashboard.widgets'))->assertOk()->json('html');
        $this->assertStringContainsString($event->title, $html);

        $html = $this->actingAs($other)->getJson(route('account.dashboard.widgets'))->assertOk()->json('html');
        $this->assertStringNotContainsString($event->title, $html);
        $this->assertStringContainsString('Пока нет предстоящих мероприятий по твоим интересам.', $html);

        VenueSubscription::query()->where('user_id', $user->id)->update(['status' => 'cancelled']);
        $html = $this->actingAs($user)->getJson(route('account.dashboard.widgets'))->assertOk()->json('html');
        $this->assertStringNotContainsString($event->title, $html);
        $widget->update(['exclude_user_ids' => [$user->id]]);
        $html = $this->getJson(route('account.dashboard.widgets'))->assertOk()->json('html');
        $this->assertStringNotContainsString('Мои интересы', $html);
    }

    public function test_partial_refresh_renders_only_requested_visible_sources_and_rechecks_catalogue(): void
    {
        $user = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $other = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $user->createProfile([]);
        $other->createProfile([]);
        $base = AccountDashboardWidget::query()->where('kind', 'message_summary')->firstOrFail();
        $messages = $base->replicate();
        $messages->forceFill([
            'kind' => 'dynamic', 'source_key' => 'unread_messages', 'title' => 'Выборочные сообщения',
            'enabled' => true, 'can_hide' => true, 'include_user_ids' => [$user->id],
            'exclude_user_ids' => [$other->id], 'actions' => [],
        ])->save();
        $wallet = $base->replicate();
        $wallet->forceFill([
            'kind' => 'dynamic', 'source_key' => 'wallet_summary', 'title' => 'Не выбранный кошелёк',
            'enabled' => true, 'can_hide' => true, 'include_user_ids' => [$user->id], 'actions' => [],
        ])->save();
        $url = route('account.dashboard.widgets', ['sources' => ['unread_messages']]);
        $this->getJson($url)->assertUnauthorized();
        $response = $this->actingAs($user)->getJson($url)->assertOk()->assertJsonPath('partial', true);
        $this->assertContains($messages->id, $response->json('visible_ids'));
        $this->assertContains($wallet->id, $response->json('visible_ids'));
        $this->assertStringContainsString('Выборочные сообщения', $response->json('html'));
        $this->assertStringNotContainsString('Не выбранный кошелёк', $response->json('html'));
        $this->assertStringNotContainsString('data-dashboard-add-widget', $response->json('html'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));

        $this->getJson(route('account.dashboard.widgets', ['sources' => ['unknown']]))->assertUnprocessable();
        $this->getJson(route('account.dashboard.widgets', ['sources' => 'unread_messages']))->assertUnprocessable();
        $foreign = $this->actingAs($other)->getJson($url)->assertOk();
        $this->assertNotContains($messages->id, $foreign->json('visible_ids'));
        $this->assertStringNotContainsString('Выборочные сообщения', $foreign->json('html'));

        $this->actingAs($user)->postJson(route('account.settings.dashboard.update'), ['visible_widget_ids' => [$wallet->id]])->assertOk();
        $hidden = $this->getJson($url)->assertOk();
        $this->assertNotContains($messages->id, $hidden->json('visible_ids'));
        $this->assertStringNotContainsString('Выборочные сообщения', $hidden->json('html'));
        $this->postJson(route('account.settings.dashboard.update'), ['visible_widget_ids' => [$messages->id, $wallet->id]])->assertOk();
        $messages->update(['exclude_user_ids' => [$user->id]]);
        $revoked = $this->getJson($url)->assertOk();
        $this->assertNotContains($messages->id, $revoked->json('visible_ids'));
        $this->assertStringNotContainsString('Выборочные сообщения', $revoked->json('html'));
    }

    public function test_personal_invalidation_contains_no_personal_payload(): void
    {
        $user = User::factory()->create();
        $broadcast = new DashboardWidgetsInvalidatedBroadcast($user, ['unread_messages', 'unread_messages'], true);

        $this->assertSame('private-users.'.$user->id, $broadcast->broadcastOn()->name);
        $this->assertSame('dashboard.widgets.invalidated', $broadcast->broadcastAs());
        $this->assertSame(['sources' => ['unread_messages'], 'catalog_changed' => true], $broadcast->broadcastWith());
    }
}
