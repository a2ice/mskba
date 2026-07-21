<?php

namespace Tests\Feature\Identity;

use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class AccountPasswordTest extends TestCase
{
    use RefreshDatabase;

    public function test_telegram_user_without_password_can_set_it_and_then_login_on_the_web(): void
    {
        $user = User::factory()->create([
            'username' => 'tg_123456',
            'password' => null,
            'password_updated_at' => null,
            'is_temporary_password' => false,
            'status' => UserStatusEnum::CONFIRMED,
        ]);

        $response = $this->actingAs($user)->put(route('account.settings.password.update'), [
            'password' => 'Strong1!',
            'password_confirmation' => 'Strong1!',
        ]);

        $response
            ->assertRedirect(route('account.settings'))
            ->assertSessionHas('status', 'Пароль установлен.');

        $user->refresh();

        $this->assertTrue(Hash::check('Strong1!', $user->password));
        $this->assertNotNull($user->password_updated_at);
        $this->assertFalse($user->is_temporary_password);

        $this->post(route('auth.logout'));

        $this->post(route('auth.login'), [
            'login' => 'tg_123456',
            'password' => 'Strong1!',
        ])->assertRedirect('/');

        $this->assertAuthenticatedAs($user);
    }

    public function test_existing_password_can_only_be_changed_with_the_current_password(): void
    {
        $user = User::factory()->create([
            'password' => 'OldStrong1!',
            'is_temporary_password' => true,
            'status' => UserStatusEnum::CONFIRMED,
        ]);

        $this->actingAs($user)->put(route('account.settings.password.update'), [
            'current_password' => 'WrongStrong1!',
            'password' => 'NewStrong2!',
            'password_confirmation' => 'NewStrong2!',
        ])->assertSessionHasErrors('current_password');

        $this->assertTrue(Hash::check('OldStrong1!', $user->refresh()->password));

        $this->actingAs($user)->put(route('account.settings.password.update'), [
            'current_password' => 'OldStrong1!',
            'password' => 'NewStrong2!',
            'password_confirmation' => 'NewStrong2!',
        ])->assertSessionHasNoErrors();

        $user->refresh();

        $this->assertTrue(Hash::check('NewStrong2!', $user->password));
        $this->assertFalse($user->is_temporary_password);
    }
}
