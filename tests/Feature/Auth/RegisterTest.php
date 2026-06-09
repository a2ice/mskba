<?php

namespace Tests\Feature\Auth;

use App\Modules\Identity\Domain\Enums\UserParticipationRoleAssignerEnum;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleEnum;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleStatusEnum;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegisterTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_register_without_participation_role(): void
    {
        $response = $this->post(route('auth.register'), $this->registrationPayload([
            'username' => 'optional_role_user',
        ]));

        $response->assertRedirect(route('login', absolute: false));

        $user = User::query()->where('username', 'optional_role_user')->firstOrFail();

        $this->assertDatabaseMissing('user_participation_roles', [
            'user_id' => $user->id,
        ]);
    }

    public function test_user_can_register_with_valid_participation_role(): void
    {
        $response = $this->post(route('auth.register'), $this->registrationPayload([
            'username' => 'player_role_user',
            'role' => UserParticipationRoleEnum::PLAYER->value,
        ]));

        $response->assertRedirect(route('login', absolute: false));

        $user = User::query()->where('username', 'player_role_user')->firstOrFail();

        $this->assertDatabaseHas('user_participation_roles', [
            'user_id' => $user->id,
            'role' => UserParticipationRoleEnum::PLAYER->value,
            'status' => UserParticipationRoleStatusEnum::ACTIVE->value,
            'assigned_by' => $user->id,
            'assigner' => UserParticipationRoleAssignerEnum::USER->value,
            'comment' => 'Выбрана пользователем при регистрации.',
        ]);
    }

    public function test_user_can_register_with_participant_role_alias(): void
    {
        $response = $this->post(route('auth.register'), $this->registrationPayload([
            'username' => 'coach_role_user',
            'participantRole' => UserParticipationRoleEnum::COACH->value,
        ]));

        $response->assertRedirect(route('login', absolute: false));

        $user = User::query()->where('username', 'coach_role_user')->firstOrFail();

        $this->assertDatabaseHas('user_participation_roles', [
            'user_id' => $user->id,
            'role' => UserParticipationRoleEnum::COACH->value,
        ]);
    }

    public function test_user_cannot_register_with_invalid_participation_role(): void
    {
        $response = $this->post(route('auth.register'), $this->registrationPayload([
            'username' => 'invalid_role_user',
            'role' => 'invalid_role',
        ]));

        $response->assertSessionHasErrors('role');

        $this->assertDatabaseMissing('users', [
            'username' => 'invalid_role_user',
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function registrationPayload(array $overrides = []): array
    {
        return array_merge([
            'username' => 'register_user',
            'password' => 'Password1!',
            'password_confirmation' => 'Password1!',
            'role' => null,
        ], $overrides);
    }
}
