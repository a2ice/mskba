<?php

namespace Tests\Feature\Dashboard;

use App\Modules\Dashboard\Domain\Models\AccountDashboardWidget;
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

    public function test_personal_invalidation_contains_no_personal_payload(): void
    {
        $user = User::factory()->create();
        $broadcast = new DashboardWidgetsInvalidatedBroadcast($user, ['unread_messages', 'unread_messages'], true);

        $this->assertSame('private-users.'.$user->id, $broadcast->broadcastOn()->name);
        $this->assertSame('dashboard.widgets.invalidated', $broadcast->broadcastAs());
        $this->assertSame(['sources' => ['unread_messages'], 'catalog_changed' => true], $broadcast->broadcastWith());
    }
}
