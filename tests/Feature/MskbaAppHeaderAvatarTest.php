<?php

namespace Tests\Feature;

use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

final class MskbaAppHeaderAvatarTest extends TestCase
{
    use RefreshDatabase;

    private string $previousTheme;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousTheme = config('themes.active');
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

    public function test_staff_roles_display_their_badge_on_the_shared_header_avatar(): void
    {
        foreach ([
            UserSystemRoleEnum::SUPERADMIN,
            UserSystemRoleEnum::ADMIN,
            UserSystemRoleEnum::MODERATOR,
            UserSystemRoleEnum::EDITOR,
        ] as $role) {
            $user = User::factory()->create([
                'username' => 'test_'.$role->value,
                'system_role' => $role,
                'status' => UserStatusEnum::CONFIRMED,
            ]);

            $response = $this->actingAs($user)->get(route('welcome'))->assertOk();
            $badge = $role->avatarBadge();

            $response->assertSee('class="avatar app-user-avatar app-header-avatar"', false)
                ->assertSee('data-avatar-role="'.$role->value.'"', false)
                ->assertSee('title="'.$role->label().'"', false)
                ->assertSee('--app-avatar-role-color: '.$badge['color'], false)
                ->assertSee('class="app-user-avatar__role-letter">'.$badge['initial'].'</span>', false)
                ->assertSee('aria-label="Личный кабинет, роль: '.$role->label().'"', false);
            $this->assertSame(1, substr_count($response->getContent(), 'data-avatar-role='));
        }
    }

    public function test_regular_and_system_accounts_have_no_staff_avatar_badge(): void
    {
        foreach ([UserSystemRoleEnum::USER, UserSystemRoleEnum::SYSTEM] as $role) {
            $user = User::factory()->create([
                'username' => 'test_'.$role->value,
                'system_role' => $role,
                'status' => UserStatusEnum::CONFIRMED,
            ]);

            $this->actingAs($user)->get(route('welcome'))->assertOk()
                ->assertSee('class="avatar app-user-avatar app-header-avatar"', false)
                ->assertSee('aria-label="Личный кабинет"', false)
                ->assertDontSee('data-avatar-role=', false);
        }
    }

    public function test_guest_does_not_render_an_account_avatar(): void
    {
        $this->get(route('welcome'))->assertOk()
            ->assertDontSee('class="avatar app-user-avatar app-header-avatar"', false)
            ->assertDontSee('data-avatar-role=', false);
    }
}
