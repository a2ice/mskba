<?php

namespace Tests\Feature\Dashboard;

use App\Modules\Dashboard\Application\Services\DashboardButtonStyleCompiler;
use App\Modules\Dashboard\Application\Services\DashboardWidgetActions;
use App\Modules\Dashboard\Domain\Models\AccountDashboardWidget;
use App\Modules\Dashboard\Domain\Models\DashboardButtonStyle;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class DashboardWidgetActionsAndStylesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('themes.active', 'mskba_app');
        app()->forgetInstance(ThemeResolver::class);
        View::replaceNamespace('theme', resource_path('themes/mskba_app/views'));
    }

    private function user(UserStatusEnum $status = UserStatusEnum::CONFIRMED): User
    {
        $user = User::factory()->create([
            'status' => $status, 'system_role' => UserSystemRoleEnum::USER,
        ]);
        $user->createProfile([]);

        return $user;
    }

    public function test_original_three_native_buttons_survive_action_migration(): void
    {
        $kinds = AccountDashboardWidget::query()->pluck('actions', 'kind');
        $this->assertSame('Мои роли', $kinds['getting_started'][0]['label']);
        $this->assertSame('Открыть сообщения', $kinds['message_summary'][0]['label']);
        $this->assertSame('unconfirmed', $kinds['account_status'][0]['visibility']);

        $confirmed = $this->user();
        $html = $this->actingAs($confirmed)->getJson(route('account.dashboard.widgets'))->assertOk()->json('html');
        $this->assertStringContainsString('Мои роли', $html);
        $this->assertStringContainsString('Открыть сообщения', $html);
        $this->assertStringNotContainsString('app-account-overview__confirmation-action', $html);

        $unconfirmed = $this->user(UserStatusEnum::UNCONFIRMED);
        $html = $this->actingAs($unconfirmed)->get(route('account'))->assertOk()->getContent();
        $this->assertStringContainsString('Подтвердить аккаунт', $html);
        $this->assertStringContainsString('app-account-overview__confirmation-action', $html);
    }

    public function test_custom_button_css_is_scoped_and_actions_order_is_preserved(): void
    {
        $style = DashboardButtonStyle::query()->create([
            'name' => 'Зелёная кнопка', 'class_name' => 'bg-green',
            'css_code' => '.bg-green { background-color: Green!important; color: white; }',
            'enabled' => true,
        ]);
        $widget = AccountDashboardWidget::query()->where('kind', 'getting_started')->firstOrFail();
        $widget->update(['actions' => [
            ['label' => 'Первая', 'url' => '/partners', 'appearance' => 'secondary',
                'visibility' => 'always', 'classes' => ['bg-green']],
            ['label' => 'Вторая', 'url' => 'https://example.org', 'appearance' => 'primary',
                'visibility' => 'always', 'classes' => []],
        ]]);

        $html = $this->actingAs($this->user())->getJson(route('account.dashboard.widgets'))->assertOk()->json('html');
        $this->assertStringContainsString('.app-dashboard-grid .app-dashboard-widget a.button.bg-green {', $html);
        $this->assertStringContainsString('background-color:Green!important', $html);
        $this->assertStringContainsString('class="button secondary bg-green"', $html);
        $this->assertStringContainsString('href="/partners"', $html);
        $this->assertTrue(strpos($html, 'Первая') < strpos($html, 'Вторая'));
        $this->assertStringContainsString('target="_blank"', $html);

        $style->update(['enabled' => false]);
        $html = $this->getJson(route('account.dashboard.widgets'))->assertOk()->json('html');
        $this->assertStringNotContainsString('a.button.bg-green', $html);
        $this->assertStringNotContainsString('class="button secondary bg-green"', $html);
    }

    public function test_css_compiler_rejects_global_selectors_scripts_and_remote_assets(): void
    {
        $compiler = app(DashboardButtonStyleCompiler::class);
        foreach ([
            'body { color:red; }',
            '.bg-green { background: url(https://example.org/tracker); }',
            '.bg-green { background: green; } header {display:none}',
            '.bg-green { color: red; position: fixed; }',
            '.bg-green { color:red; }</style><script>alert(1)</script>',
            '@import "https://example.org/css";',
        ] as $badCss) {
            try {
                $compiler->parse('bg-green', $badCss);
                $this->fail('CSS must be rejected: '.$badCss);
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_multi_rule_pseudo_classes_and_pseudo_elements_are_compiled_only_for_widget_buttons(): void
    {
        $compiler = app(DashboardButtonStyleCompiler::class);
        $css = <<<'CSS'
.cta-button-01 {
    background-color: purple !important;
    position: relative;
    overflow: hidden;
}
.cta-button-01:before {
    content: "";
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(255,255,255,.5);
}
.cta-button-01:hover::before {
    opacity: .25;
}
.cta-button-01:focus-visible {
    outline: 2px solid white;
}
CSS;

        $parsed = $compiler->parse('cta-button-01', $css);
        $this->assertCount(4, $parsed);

        $style = DashboardButtonStyle::query()->create([
            'name' => 'CTA-кнопка', 'class_name' => 'cta-button-01',
            'css_code' => $css, 'enabled' => true,
        ]);
        $compiled = $compiler->compile($style);
        $root = '.app-dashboard-grid .app-dashboard-widget a.button.cta-button-01';
        $this->assertStringContainsString($root.' {', $compiled);
        $this->assertStringContainsString($root.'::before {', $compiled);
        $this->assertStringContainsString($root.':hover::before {', $compiled);
        $this->assertStringContainsString($root.':focus-visible {', $compiled);
        $this->assertStringContainsString('pointer-events:none;', $compiled);
        $this->assertStringContainsString('content:"";', $compiled);
        $this->assertStringNotContainsString("\nbody", $compiled);
        $this->assertStringNotContainsString("\n.cta-button-01", $compiled);
    }

    public function test_css_pseudo_selectors_cannot_escape_widget_scope_or_load_external_content(): void
    {
        $compiler = app(DashboardButtonStyleCompiler::class);
        foreach ([
            '.cta-button-01:hover, body {color:red;}',
            '.cta-button-01::before { content: "Untrusted text"; }',
            '.cta-button-01::before { position: fixed; }',
            '.cta-button-01::before { background: url(https://evil.example/x); }',
            '.cta-button-01::before { width: 100vw; }',
            '.cta-button-01::before { pointer-events: auto; }',
            '.cta-button-01:hover a {color:red;}',
            '.cta-button-01:not(.other) {color:red;}',
            '.cta-button-01::first-letter {color:red;}',
            '.cta-button-01 {color:red;} .other {color:blue;}',
            '.cta-button-01 { color:red; } @import url(https://evil.example/x);',
        ] as $unsafe) {
            try {
                $compiler->parse('cta-button-01', $unsafe);
                $this->fail('Unsafe CSS was accepted: '.$unsafe);
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_button_links_are_always_validated_and_conditionally_visible(): void
    {
        $svc = app(DashboardWidgetActions::class);
        $valid = [
            ['label' => 'Роли', 'url' => '/account/roles',
                'appearance' => 'secondary', 'visibility' => 'confirmed', 'classes' => []],
        ];
        $this->assertCount(1, $svc->validate($valid, 'getting_started'));
        $this->expectException(ValidationException::class);
        $svc->validate([array_replace($valid[0], ['url' => 'javascript:alert(1)'])], 'information');
    }
}
