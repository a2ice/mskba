<?php

namespace Tests\Feature\Dashboard;

use App\Filament\Backoffice\Resources\AccountDashboardWidgets\Pages\EditAccountDashboardWidget;
use App\Modules\Dashboard\Application\Services\DashboardWidgetSaveCooldown;
use App\Modules\Dashboard\Domain\Models\AccountDashboardWidget;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Presentation\Theming\ThemeResolver;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Livewire\Livewire;
use Tests\TestCase;

final class DashboardWidgetLayoutDelayTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('themes.active', 'mskba_app');
        app()->forgetInstance(ThemeResolver::class);
        View::replaceNamespace('theme', resource_path('themes/mskba_app/views'));
    }

    private function user(UserSystemRoleEnum $role = UserSystemRoleEnum::USER): User
    {
        $user = User::factory()->create([
            'system_role' => $role, 'status' => UserStatusEnum::CONFIRMED,
        ]);
        $user->createProfile([]);

        return $user;
    }

    public function test_every_widget_kind_accepts_image_and_renders_image_next_to_content(): void
    {
        $image = 'https://example.org/picture.jpg';
        $kinds = ['account_status', 'getting_started', 'message_summary',
            'information', 'advertisement', 'custom_html'];

        foreach ($kinds as $index => $kind) {
            $widget = AccountDashboardWidget::query()->where('kind', $kind)->first();
            $widget ??= AccountDashboardWidget::query()->create([
                'kind' => $kind, 'title' => $kind, 'enabled' => true,
                'can_hide' => true, 'size' => 'half', 'contact_filter' => 'any',
                'sort_order' => 100 + $index,
            ]);
            $widget->update([
                'image_url' => $image,
                'image_position' => $index % 2 ? 'right' : 'left',
                'image_width_percent' => 25,
                'image_gap_px' => 10,
                'refresh_delay_seconds' => 5,
                'html_code' => $kind === 'custom_html' ? '<strong>Sandbox</strong>' : null,
            ]);
        }

        $html = $this->actingAs($this->user())->getJson(route('account.dashboard.widgets'))
            ->assertOk()->json('html');

        $this->assertSame(6, substr_count($html, 'class="app-dashboard-widget__image"'));
        $this->assertSame(6, substr_count($html, 'data-widget-refresh-delay="5"'));
        $this->assertStringContainsString('app-dashboard-widget__composition--left', $html);
        $this->assertStringContainsString('app-dashboard-widget__composition--right', $html);
        $this->assertStringContainsString('--widget-image-fr: 25fr', $html);
        $this->assertStringContainsString('--widget-text-fr: 75fr', $html);
        $this->assertStringContainsString('--widget-image-gap: 10px', $html);
        $this->assertStringContainsString('sandbox="allow-scripts"', $html);
        $this->assertStringContainsString('data-widget-version="', $html);
    }

    public function test_full_width_grid_image_stacks_and_horizontal_gap_is_configurable(): void
    {
        $widget = AccountDashboardWidget::query()->where('kind', 'getting_started')->firstOrFail();
        $widget->update([
            'image_url' => 'https://example.org/promo.png',
            'image_position' => 'right', 'image_width_percent' => 100, 'image_gap_px' => 18,
            'refresh_delay_seconds' => 0,
        ]);
        $html = $this->actingAs($this->user())->getJson(route('account.dashboard.widgets'))
            ->assertOk()->json('html');

        $this->assertStringContainsString('app-dashboard-widget__composition--top', $html);
        $this->assertStringNotContainsString('app-dashboard-widget__composition--right', $html);
        $this->assertStringContainsString('--widget-image-gap: 18px', $html);
        $this->assertStringContainsString('data-widget-refresh-delay="0"', $html);
    }

    public function test_filament_rejects_rapid_second_save_but_allows_after_timer(): void
    {
        $editor = $this->user(UserSystemRoleEnum::SUPERADMIN);
        $widget = AccountDashboardWidget::query()->where('kind', 'getting_started')->firstOrFail();
        $this->actingAs($editor);
        Filament::setCurrentPanel(Filament::getPanel('backoffice'));

        Livewire::test(EditAccountDashboardWidget::class, ['record' => $widget->id])
            ->fillForm(['title' => 'Первая версия', 'refresh_delay_seconds' => 5])
            ->call('save')->assertHasNoFormErrors();
        $this->assertSame('Первая версия', $widget->fresh()->title);
        $this->assertSame(5, app(DashboardWidgetSaveCooldown::class)->remaining($widget->fresh()));

        Livewire::test(EditAccountDashboardWidget::class, ['record' => $widget->id])
            ->fillForm(['title' => 'Не должно сохраниться'])
            ->call('save')->assertHasFormErrors(['refresh_delay_seconds']);
        $this->assertSame('Первая версия', $widget->fresh()->title);

        $this->travel(6)->seconds();
        Livewire::test(EditAccountDashboardWidget::class, ['record' => $widget->id])
            ->fillForm(['title' => 'Следующая версия'])
            ->call('save')->assertHasNoFormErrors();
        $this->assertSame('Следующая версия', $widget->fresh()->title);
    }

    public function test_zero_delay_allows_immediate_repeat_save(): void
    {
        $this->actingAs($this->user(UserSystemRoleEnum::SUPERADMIN));
        Filament::setCurrentPanel(Filament::getPanel('backoffice'));
        $widget = AccountDashboardWidget::query()->where('kind', 'getting_started')->firstOrFail();

        Livewire::test(EditAccountDashboardWidget::class, ['record' => $widget->id])
            ->fillForm(['title' => 'Один', 'refresh_delay_seconds' => 0])
            ->call('save')->assertHasNoFormErrors();

        Livewire::test(EditAccountDashboardWidget::class, ['record' => $widget->id])
            ->fillForm(['title' => 'Два'])
            ->call('save')->assertHasNoFormErrors();

        $this->assertSame('Два', $widget->fresh()->title);
        $this->assertSame(0, app(DashboardWidgetSaveCooldown::class)->remaining($widget->fresh()));
    }

    public function test_filament_save_button_has_countdown_and_editor_has_no_legacy_link_fields(): void
    {
        $this->actingAs($this->user(UserSystemRoleEnum::SUPERADMIN));
        Filament::setCurrentPanel(Filament::getPanel('backoffice'));
        $widget = AccountDashboardWidget::query()->where('kind', 'advertisement')->first();
        $widget ??= AccountDashboardWidget::query()->create([
            'kind' => 'advertisement', 'title' => 'Тест', 'enabled' => true,
            'can_hide' => true, 'size' => 'half', 'contact_filter' => 'any',
            'sort_order' => 12,
        ]);
        $response = $this->get('/backoffice/account-dashboard-widgets/'.$widget->id.'/edit')
            ->assertOk()->assertSee('Изображение')->assertSee('Gap (px)');
        $markup = $response->getContent();

        $this->assertStringContainsString('dashboard-widget-save-locked', $markup);
        $this->assertStringContainsString('x-bind:disabled', $markup);
        $this->assertStringNotContainsString('Совместимость с прежним редактором', $markup);
        $this->assertStringNotContainsString('Текст кнопки', $markup);
    }

    public function test_forged_layout_and_non_http_image_are_rejected_server_side(): void
    {
        $this->actingAs($this->user(UserSystemRoleEnum::SUPERADMIN));
        Filament::setCurrentPanel(Filament::getPanel('backoffice'));
        $widget = AccountDashboardWidget::query()->where('kind', 'getting_started')->firstOrFail();

        Livewire::test(EditAccountDashboardWidget::class, ['record' => $widget->id])
            ->fillForm(['image_position' => 'fixed-overlay'])
            ->call('save')->assertHasFormErrors();

        Livewire::test(EditAccountDashboardWidget::class, ['record' => $widget->id])
            ->fillForm(['image_url' => 'javascript:alert(1)'])
            ->call('save')->assertHasFormErrors();
    }
}
