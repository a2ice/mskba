<?php

namespace Tests\Feature\Auth;

use App\Modules\Identity\Domain\Enums\UserParticipationRoleAssignerEnum;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleEnum;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleStatusEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Identity\Domain\Models\UserConsent;
use App\Modules\Telegram\Infrastructure\Http\Middleware\RouteScopedThrottleRequests;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegisterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Registration checks are independent of throttle behavior, which has
        // dedicated tests; keep this suite stable as cases exceed 5/minute.
        $this->withoutMiddleware(RouteScopedThrottleRequests::class);
    }

    public function test_user_can_register_without_participation_role(): void
    {
        $response = $this->post(route('auth.register'), $this->registrationPayload([
            'username' => 'optional_role_user',
        ]));

        $response->assertRedirect(route('account.privacy.distribution'));

        $user = User::query()->where('username', 'optional_role_user')->firstOrFail();

        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->personal_data_distribution_required_at);
        $this->assertNull($user->personal_data_distribution_setup_completed_at);

        $this->assertDatabaseMissing('user_participation_roles', [
            'user_id' => $user->id,
        ]);

        $this->assertDatabaseHas('user_consents', [
            'user_id' => $user->id,
            'type' => UserConsent::TYPE_PERSONAL_DATA_PROCESSING,
            'document_version' => config('legal.personal_data_consent_version'),
            'source' => 'site_registration',
        ]);
    }

    public function test_user_can_register_with_valid_participation_role(): void
    {
        $response = $this->post(route('auth.register'), $this->registrationPayload([
            'username' => 'player_role_user',
            'role' => UserParticipationRoleEnum::PLAYER->value,
        ]));

        $response->assertRedirect(route('account.privacy.distribution'));

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

        $response->assertRedirect(route('account.privacy.distribution'));

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
            ->assertJsonPath('redirect_url', route('account.privacy.distribution'));
        $this->assertSame(route('venues.create'), session('privacy.distribution.return_to'));
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
            ->assertJsonPath('redirect_url', route('account.privacy.distribution'));
        $this->assertSame(route('account'), session('privacy.distribution.return_to'));
    }

    public function test_registration_requires_personal_data_consent(): void
    {
        $response = $this->post(route('auth.register'), $this->registrationPayload([
            'username' => 'without_personal_data_consent',
            'privacy_consent' => null,
        ]));

        $response->assertSessionHasErrors('privacy_consent');

        $this->assertDatabaseMissing('users', [
            'username' => 'without_personal_data_consent',
        ]);
    }

    public function test_player_registration_persists_optional_sport_profile_atomically(): void
    {
        $response = $this->postJson(route('auth.register'), $this->registrationPayload([
            'username' => 'wizard_player_user',
            'role' => UserParticipationRoleEnum::PLAYER->value,
            'gender' => 'male',
            'birth_date' => '2000-03-04',
            'first_name' => 'Никита',
            'height_cm' => 181,
            'weight_kg' => 77,
            'position' => 'point_guard',
            'body_type' => 'athletic',
            'experience_started_year' => now()->year - 12,
        ]));

        $response->assertCreated()
            ->assertJsonPath('redirect_url', route('account.privacy.distribution'));

        $user = User::query()->where('username', 'wizard_player_user')->firstOrFail();
        $this->assertDatabaseHas('player_profiles', [
            'user_id' => $user->id,
            'height_cm' => 181,
            'weight_kg' => 77,
            'body_type' => 'athletic',
            'experience_started_year' => now()->year - 12,
        ]);
        $player = $user->playerProfile()->firstOrFail();
        $this->assertDatabaseHas('player_profile_positions', [
            'player_profile_id' => $player->id,
            'position' => 'point_guard',
        ]);
        $this->assertSame('Никита', $user->profile?->first_name);
    }

    public function test_nonplayer_registration_ignores_hidden_player_fields(): void
    {
        $response = $this->postJson(route('auth.register'), $this->registrationPayload([
            'username' => 'wizard_coach_user',
            'role' => 'coach',
            'height_cm' => 181,
            'position' => 'center',
        ]));
        $response->assertCreated();
        $user = User::query()->where('username', 'wizard_coach_user')->firstOrFail();
        $this->assertDatabaseMissing('player_profiles', ['user_id' => $user->id]);
    }

    public function test_invalid_player_attribute_aborts_registration(): void
    {
        $response = $this->postJson(route('auth.register'), $this->registrationPayload([
            'username' => 'wizard_invalid_player',
            'role' => 'player',
            'height_cm' => 300,
            'position' => 'invalid',
        ]));
        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['height_cm', 'position']);
        $this->assertDatabaseMissing('users', ['username' => 'wizard_invalid_player']);
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
            'privacy_consent' => '1',
            'role' => null,
        ], $overrides);
    }
}
