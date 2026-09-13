<?php

namespace Tests\Feature\Team;

use App\Modules\Identity\Domain\Enums\Participation\PlayerPositionEnum;
use App\Modules\Identity\Domain\Enums\UserGenderEnum;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Team\Domain\Enums\TeamHiringStatusEnum;
use App\Modules\Team\Domain\Enums\TeamJoinRequestStatusEnum;
use App\Modules\Team\Domain\Models\Team;
use App\Modules\Team\Domain\Models\TeamHiringPosition;
use App\Modules\Team\Domain\Models\TeamJoinRequest;
use Database\Seeders\GameLifecycleDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class TeamHiringTest extends TestCase
{
    use RefreshDatabase;

    public function test_targeted_hiring_application_fills_and_closes_vacancy(): void
    {
        $this->seed(GameLifecycleDemoSeeder::class);
        $creator = User::query()->where('username', GameLifecycleDemoSeeder::ORGANIZER_USERNAME)->firstOrFail();
        $team = Team::query()->where('alias', 'demo-red')->firstOrFail();
        $team->update(['accepts_join_requests' => false]);

        $this->actingAs($creator)
            ->post(route('teams.hiring.store', $team->routeIdentifier()), [
                'spots_total' => 1,
                'positions' => [PlayerPositionEnum::CENTER->value, PlayerPositionEnum::POWER_FORWARD->value],
                'minimum_experience_years' => 5,
                'gender' => UserGenderEnum::FEMALE->value,
                'description' => 'Ищем игрока на вечерние тренировки.',
            ])
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertTrue($team->fresh()->accepts_join_requests);
        $vacancy = TeamHiringPosition::query()->sole();
        $this->assertSame(TeamHiringStatusEnum::ACTIVE, $vacancy->status);
        $this->assertSame([PlayerPositionEnum::CENTER->value, PlayerPositionEnum::POWER_FORWARD->value], $vacancy->positions);
        $this->actingAs($creator)
            ->get(route('teams.hiring.index', $team->routeIdentifier()))
            ->assertOk()
            ->assertSee('Открыть вакансию')
            ->assertSee('Минимальный опыт, лет');
        $this->get(route('teams.index', ['hiring' => 1]))
            ->assertOk()
            ->assertSee($team->name)
            ->assertSee('Идёт набор');
        auth()->logout();
        $vacancyIntent = 'team:'.$team->id.':vacancy:'.$vacancy->id;
        $afterAuthenticationUrl = route('teams.show', [
            'team' => $team->routeIdentifier(),
            'team_join_intent' => $vacancyIntent,
        ], false);
        $this->get(route('teams.show', $team->routeIdentifier()))
            ->assertOk()
            ->assertSee('Ищем игроков')
            ->assertSee('Центровой')
            ->assertSee('Ищем игрока на вечерние тренировки.')
            ->assertSee('data-modal-target="auth-entry-classic"', false)
            ->assertSee('data-team-join-auth-intent="'.$vacancyIntent.'"', false)
            ->assertSee('data-auth-redirect-url="'.$afterAuthenticationUrl.'"', false);

        $candidate = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $candidate->profile()->create(['gender' => UserGenderEnum::MALE]);
        $this->actingAs($candidate)
            ->get($afterAuthenticationUrl)
            ->assertOk()
            ->assertSee('data-team-join-auto-form="'.$vacancyIntent.'"', false)
            ->assertSee('name="team_hiring_position_id" value="'.$vacancy->id.'"', false);
        $this->actingAs($candidate)
            ->post(route('teams.join-requests.store', $team->routeIdentifier()), [
                'team_hiring_position_id' => $vacancy->id,
            ])
            ->assertRedirect();

        $joinRequest = TeamJoinRequest::query()->where('user_id', $candidate->id)->sole();
        $this->assertSame($vacancy->id, $joinRequest->team_hiring_position_id);
        $this->assertSame(TeamJoinRequestStatusEnum::PENDING, $joinRequest->status);
        $this->get(route('teams.show', $team->routeIdentifier()))
            ->assertOk()
            ->assertSee('Заявка на рассмотрении')
            ->assertDontSee('data-team-join-auto-form', false);

        $this->actingAs($creator)
            ->patch(route('teams.join-requests.respond', [$team->routeIdentifier(), $joinRequest->id]), [
                'action' => 'accept',
            ])
            ->assertRedirect();

        $vacancy->refresh();
        $this->assertSame(1, $vacancy->spots_filled);
        $this->assertSame(TeamHiringStatusEnum::CLOSED, $vacancy->status);
        $this->assertNotNull($vacancy->closed_at);

        $secondCandidate = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $this->actingAs($secondCandidate)
            ->post(route('teams.join-requests.store', $team->routeIdentifier()), [
                'team_hiring_position_id' => $vacancy->id,
            ])
            ->assertUnprocessable();
    }

    public function test_active_hiring_requires_global_application_gate_and_reopen_restores_it(): void
    {
        $this->seed(GameLifecycleDemoSeeder::class);
        $creator = User::query()->where('username', GameLifecycleDemoSeeder::ORGANIZER_USERNAME)->firstOrFail();
        $stranger = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $team = Team::query()->where('alias', 'demo-red')->firstOrFail();
        $team->update(['accepts_join_requests' => true]);

        $this->actingAs($stranger)
            ->post(route('teams.hiring.store', $team->routeIdentifier()), ['spots_total' => 1])
            ->assertForbidden();

        $vacancy = TeamHiringPosition::query()->create([
            'team_id' => $team->id,
            'status' => TeamHiringStatusEnum::ACTIVE,
            'spots_total' => 2,
            'spots_filled' => 0,
            'created_by_user_id' => $creator->id,
        ]);

        $this->actingAs($creator)
            ->patch(route('teams.settings.applications.update', $team->routeIdentifier()), [
                'accepts_join_requests' => 0,
            ])
            ->assertUnprocessable();
        $this->assertTrue($team->fresh()->accepts_join_requests);

        $this->actingAs($creator)
            ->patch(route('teams.hiring.status', [$team->routeIdentifier(), $vacancy->id]), ['action' => 'close'])
            ->assertRedirect();
        $this->actingAs($creator)
            ->patch(route('teams.settings.applications.update', $team->routeIdentifier()), [
                'accepts_join_requests' => 0,
            ])
            ->assertRedirect();
        $this->assertFalse($team->fresh()->accepts_join_requests);

        $this->actingAs($creator)
            ->patch(route('teams.hiring.status', [$team->routeIdentifier(), $vacancy->id]), ['action' => 'reopen'])
            ->assertRedirect()
            ->assertSessionHas('status');
        $this->assertTrue($team->fresh()->accepts_join_requests);
        $this->assertSame(TeamHiringStatusEnum::ACTIVE, $vacancy->fresh()->status);
    }

    public function test_targeted_application_cannot_bypass_closed_global_gate_even_with_active_vacancy(): void
    {
        $this->seed(GameLifecycleDemoSeeder::class);
        $team = Team::query()->where('alias', 'demo-red')->firstOrFail();
        $creator = User::query()->where('username', GameLifecycleDemoSeeder::ORGANIZER_USERNAME)->firstOrFail();
        $vacancy = TeamHiringPosition::query()->create([
            'team_id' => $team->id,
            'status' => TeamHiringStatusEnum::ACTIVE,
            'spots_total' => 2,
            'spots_filled' => 0,
            'created_by_user_id' => $creator->id,
        ]);
        $team->update(['accepts_join_requests' => false]);
        $candidate = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);

        $this->actingAs($candidate)
            ->post(route('teams.join-requests.store', $team->routeIdentifier()), [
                'team_hiring_position_id' => $vacancy->id,
            ])
            ->assertUnprocessable();
        $this->assertDatabaseCount('team_join_requests', 0);
    }
}
