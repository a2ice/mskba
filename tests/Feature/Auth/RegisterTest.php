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

        $response->assertRedirect(route('account'));

        $user = User::query()->where('username', 'optional_role_user')->firstOrFail();

        $this->assertAuthenticatedAs($user);

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

        $response->assertRedirect(route('account'));

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

        $response->assertRedirect(route('account'));

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

    public function test_registration_logs_user_in_and_returns_to_safe_internal_url(): void
    {
        $response = $this->postJson(route('auth.register'), $this->registrationPayload([
            'username' => 'venue_creator',
            'redirect_to' => route('venues.create', absolute: false),
        ]));

        $user = User::query()->where('username', 'venue_creator')->firstOrFail();

        $response
            ->assertCreated()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('redirect_url', route('venues.create'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_registration_rejects_external_redirect_target(): void
    {
        $response = $this
            ->from(route('welcome'))
            ->postJson(route('auth.register'), $this->registrationPayload([
                'username' => 'safe_redirect_user',
                'redirect_to' => 'https://example.com/steal-session',
            ]));

        $response
            ->assertCreated()
            ->assertJsonPath('redirect_url', route('account'));
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
