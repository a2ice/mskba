<?php

namespace Tests\Feature;

use App\Modules\Identity\Domain\Models\Profile;
use App\Modules\Identity\Domain\Models\User;
use App\Presentation\Identity\UserAddressing;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

final class MskbaAppAccountAddressingTest extends TestCase
{
    use RefreshDatabase;

    private string $previousTheme;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousTheme = (string) config('themes.active');
        config()->set('themes.active', 'mskba_app');
        app()->forgetInstance(ThemeResolver::class);
        View::replaceNamespace('theme', resource_path('themes/mskba_app/views'));
    }

    protected function tearDown(): void
    {
        config()->set('themes.active', $this->previousTheme);
        View::replaceNamespace('theme', resource_path('themes/'.$this->previousTheme.'/views'));
        app()->forgetInstance(ThemeResolver::class);
        parent::tearDown();
    }

    public function test_unicode_capitalization_and_original_login_fallback(): void
    {
        $formatter = app(UserAddressing::class);
        $user = User::factory()->make(['username' => 'LoGiN_123']);

        $this->assertSame('LoGiN_123', $formatter->greetingName($user));

        $user->setRelation('profile', new Profile(['first_name' => '  дмитрий  ']));
        $this->assertSame('Дмитрий', $formatter->greetingName($user));

        $user->setRelation('profile', new Profile(['first_name' => 'alex']));
        $this->assertSame('Alex', $formatter->greetingName($user));

        $user->setRelation('profile', new Profile(['first_name' => '   ']));
        $this->assertSame('LoGiN_123', $formatter->greetingName($user));

        $this->assertSame('участник', $formatter->greetingName(null));
        $this->assertSame('участник', $formatter->greetingName(User::factory()->make(['username' => null])));
    }

    public function test_account_greeting_uses_given_name_and_role_neutral_copy(): void
    {
        $user = User::factory()->create([
            'username' => 'salutation_020',
            'personal_data_distribution_required_at' => now(),
            'personal_data_distribution_setup_completed_at' => null,
        ]);
        $user->profile()->create(['first_name' => 'мария']);

        $this->actingAs($user)->get(route('account'))
            ->assertOk()
            ->assertSee('Привет, Мария!')
            ->assertSee('Здесь ты можешь управлять своим профилем, уведомлениями и настройками портала.')
            ->assertSee('Чтобы начать пользоваться возможностями портала, заверши регистрацию, а пока можешь осмотреться.')
            ->assertDontSee('Здесь собраны ваши данные, команды')
            ->assertDontSee('Укажите настройки приватности, чтобы пользоваться действиями портала');
    }

    public function test_account_without_given_name_uses_original_username(): void
    {
        $user = User::factory()->create([
            'username' => 'MiXeD_Username',
            'personal_data_distribution_required_at' => now(),
            'personal_data_distribution_setup_completed_at' => null,
        ]);

        $this->actingAs($user)->get(route('account'))
            ->assertOk()
            ->assertSee('Привет, MiXeD_Username!')
            ->assertSee('data-onboarding-pending', false);
    }

    public function test_pending_avatar_goes_to_account_not_private_page_and_shows_status(): void
    {
        $user = User::factory()->create([
            'username' => 'avatar_link_020',
            'personal_data_distribution_required_at' => now(),
            'personal_data_distribution_setup_completed_at' => null,
        ]);

        $html = $this->actingAs($user)->get(route('account'))->assertOk()->getContent();

        $this->assertSame(1, preg_match('~<a[^>]*class="[^"]*app-header-account[^"]*"[^>]*>~s', $html, $match));
        $this->assertStringContainsString('href="'.route('account').'"', $match[0]);
        $this->assertStringContainsString('title="Остался последний шаг регистрации"', $match[0]);
        $this->assertStringNotContainsString('data-open-onboarding', $match[0]);
        $this->assertStringNotContainsString('href="'.route('account.privacy.distribution').'"', $match[0]);
    }
}
