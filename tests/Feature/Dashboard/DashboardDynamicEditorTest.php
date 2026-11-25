<?php

namespace Tests\Feature\Dashboard;

use App\Filament\Backoffice\Resources\AccountDashboardWidgets\AccountDashboardWidgetResource;
use App\Filament\Backoffice\Resources\AccountDashboardWidgets\Pages\EditAccountDashboardWidget;
use App\Modules\Dashboard\Application\Services\DashboardDataRegistry;
use App\Modules\Dashboard\Domain\Models\AccountDashboardWidget;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

final class DashboardDynamicEditorTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $admin = User::factory()->create([
            'status' => UserStatusEnum::CONFIRMED,
            'system_role' => UserSystemRoleEnum::SUPERADMIN,
        ]);
        $admin->createProfile([]);

        return $admin;
    }

    public function test_filament_saves_and_publishes_dynamic_widget(): void
    {
        $this->actingAs($this->admin());
        Filament::setCurrentPanel(Filament::getPanel('backoffice'));
        $widget = AccountDashboardWidget::query()->where('kind', 'getting_started')->firstOrFail()->replicate();
        $widget->forceFill(['title' => 'Черновик интересов', 'enabled' => false, 'refresh_delay_seconds' => 0])->save();

        Livewire::test(EditAccountDashboardWidget::class, ['record' => $widget->id])
            ->fillForm(['kind' => 'dynamic', 'source_key' => 'my_interests', 'source_settings' => ['limit' => 7], 'enabled' => true])
            ->call('save')->assertHasNoFormErrors();

        $widget->refresh();
        $this->assertSame('dynamic', $widget->kind);
        $this->assertSame('my_interests', $widget->source_key);
        $this->assertSame(['limit' => 7], $widget->source_settings);
        $this->assertTrue($widget->enabled);
    }

    public function test_server_validates_source_and_normalizes_settings(): void
    {
        $this->actingAs($this->admin());
        $this->assertSame(['unread_messages', 'my_interests', 'wallet_summary', 'upcoming_events', 'my_rental_requests'], array_keys(DashboardDataRegistry::options()));

        foreach ([
            ['source_key' => 'unknown', 'source_settings' => ['limit' => 5]],
            ['source_key' => 'my_interests', 'source_settings' => ['limit' => 11]],
        ] as $invalid) {
            $data = ['kind' => 'dynamic'] + $invalid;
            try {
                AccountDashboardWidgetResource::assertSafeData($data);
                $this->fail('Invalid source configuration accepted.');
            } catch (ValidationException $exception) {
                $this->assertNotEmpty($exception->errors());
            }
        }

        $data = ['kind' => 'dynamic', 'source_key' => 'unread_messages', 'source_settings' => ['limit' => 9, 'unknown' => true], 'enabled' => true];
        AccountDashboardWidgetResource::assertSafeData($data);
        $this->assertTrue($data['enabled']);
        $this->assertSame([], $data['source_settings']);
        $data['kind'] = 'information';
        AccountDashboardWidgetResource::assertSafeData($data);
        $this->assertNull($data['source_key']);
        $this->assertNull($data['source_settings']);
    }
}
