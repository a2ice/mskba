<?php

namespace Tests\Feature\Tournament;

use App\Modules\Event\Domain\Enums\GameFormatEnum;
use App\Modules\Identity\Domain\Enums\UserRegistrationChannelEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tournament\Domain\Enums\TournamentAdmissionSourceEnum;
use App\Modules\Tournament\Domain\Enums\TournamentRecruitmentModeEnum;
use App\Modules\Tournament\Domain\Models\Tournament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class TournamentOnSiteRegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_can_register_and_apply_while_on_site_registration_is_enabled(): void
    {
        $tournament = Tournament::factory()->create([
            'format' => GameFormatEnum::STREETBALL_3X3,
            'recruitment_mode' => TournamentRecruitmentModeEnum::INDIVIDUAL_DRAFT,
            'allows_on_site_registration' => true,
        ]);

        $response = $this->post(route('tournaments.on-site.store', $tournament->routeIdentifier()), [
            'username' => 'WalkIn.Player',
            'roles' => ['player', 'coach'],
            'privacy_consent' => '1',
        ]);

        $user = User::query()->where('username', 'walkin.player')->firstOrFail();
        $response->assertRedirect(route('tournaments.on-site.show', $tournament->routeIdentifier()));
        $this->assertAuthenticatedAs($user);
        $this->assertSame(UserRegistrationChannelEnum::TOURNAMENT_ON_SITE, $user->registration_channel);
        $this->assertNull($user->password);
        $this->assertFalse($user->is_temporary_password);
        $this->assertDatabaseHas('tournament_admissions', [
            'tournament_id' => $tournament->id,
            'user_id' => $user->id,
            'source' => TournamentAdmissionSourceEnum::ON_SITE->value,
            'status' => 'pending',
        ]);
        $this->assertDatabaseHas('user_participation_roles', ['user_id' => $user->id, 'role' => 'player', 'status' => 'active']);
        $this->assertDatabaseHas('user_consents', ['user_id' => $user->id, 'source' => 'tournament_on_site_registration']);
    }

    public function test_closed_on_site_registration_rejects_submission_without_creating_user(): void
    {
        $tournament = Tournament::factory()->create([
            'format' => GameFormatEnum::STREETBALL_3X3,
            'recruitment_mode' => TournamentRecruitmentModeEnum::INDIVIDUAL_DRAFT,
            'allows_on_site_registration' => false,
        ]);

        $this->from(route('tournaments.on-site.show', $tournament->routeIdentifier()))
            ->post(route('tournaments.on-site.store', $tournament->routeIdentifier()), [
                'username' => 'walkin-player',
                'roles' => ['player'],
                'privacy_consent' => '1',
            ])->assertRedirect()->assertSessionHas('error');

        $this->assertDatabaseMissing('users', ['username' => 'walkin-player']);
    }
}
