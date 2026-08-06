<?php

namespace Tests\Feature\Team;

use App\Modules\Contract\Domain\Enums\ContractStatusEnum;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Team\Domain\Enums\TeamInvitationStatusEnum;
use App\Modules\Team\Domain\Enums\TeamJoinRequestStatusEnum;
use App\Modules\Team\Domain\Models\Team;
use App\Modules\Team\Domain\Models\TeamJoinRequest;
use Database\Seeders\GameLifecycleDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class TeamJoinRequestsTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_enables_applications_and_accepts_request(): void
    {
        $this->seed(GameLifecycleDemoSeeder::class);
        $owner = User::query()->where('username', GameLifecycleDemoSeeder::ORGANIZER_USERNAME)->firstOrFail();
        $applicant = User::factory()->create([
            'username' => 'team-applicant',
            'status' => UserStatusEnum::CONFIRMED,
        ]);
        $applicant->profile()->create(['first_name' => 'Новый', 'last_name' => 'Игрок']);
        $team = Team::query()->where('alias', 'demo-red')->firstOrFail();

        $this->actingAs($owner)
            ->patch(route('teams.settings.applications.update', $team->routeIdentifier()), [
                'accepts_join_requests' => 1,
            ])
            ->assertRedirect();
        $this->assertTrue($team->fresh()->accepts_join_requests);

        $this->actingAs($applicant)
            ->get(route('teams.show', $team->routeIdentifier()))
            ->assertOk()
            ->assertSee('Подать заявку');

        $this->post(route('teams.join-requests.store', $team->routeIdentifier()))
            ->assertRedirect()
            ->assertSessionHas('status', 'Заявка на вступление отправлена.');

        $entry = TeamJoinRequest::query()
            ->where('team_id', $team->id)
            ->where('user_id', $applicant->id)
            ->firstOrFail();
        $this->assertSame(TeamJoinRequestStatusEnum::PENDING, $entry->status);

        $this->actingAs($owner)
            ->get(route('teams.join-requests.index', $team->routeIdentifier()))
            ->assertOk()
            ->assertSee('Новый Игрок')
            ->assertSee('Принять')
            ->assertSee('Заблокировать');

        $this->patch(route('teams.join-requests.respond', [$team->routeIdentifier(), $entry->id]), [
            'action' => 'accept',
        ])->assertRedirect()
            ->assertSessionHas('status', 'Заявка принята. Пользователь добавлен в команду.');

        $this->assertSame(TeamJoinRequestStatusEnum::ACCEPTED, $entry->fresh()->status);
        $membership = $team->memberships()->where('user_id', $applicant->id)->firstOrFail();
        $this->assertSame(TeamInvitationStatusEnum::ACCEPTED, $membership->invitation_status);
        $this->assertSame(ContractStatusEnum::ACTIVE, $membership->contract->status);
        $this->assertCount(0, $membership->contract->permissions);
    }

    public function test_blocked_applicant_cannot_reapply_until_unblocked(): void
    {
        $this->seed(GameLifecycleDemoSeeder::class);
        $owner = User::query()->where('username', GameLifecycleDemoSeeder::ORGANIZER_USERNAME)->firstOrFail();
        $applicant = User::factory()->create([
            'username' => 'blocked-applicant',
            'status' => UserStatusEnum::CONFIRMED,
        ]);
        $team = Team::query()->where('alias', 'demo-red')->firstOrFail();
        $team->update(['accepts_join_requests' => true]);

        $this->actingAs($applicant)
            ->post(route('teams.join-requests.store', $team->routeIdentifier()))
            ->assertRedirect();
        $entry = TeamJoinRequest::query()->where('team_id', $team->id)->where('user_id', $applicant->id)->firstOrFail();

        $this->actingAs($owner)
            ->patch(route('teams.join-requests.respond', [$team->routeIdentifier(), $entry->id]), [
                'action' => 'reject',
            ])->assertSessionHasErrorsIn('joinRequest'.$entry->id, 'review_reason');
        $this->assertSame(TeamJoinRequestStatusEnum::PENDING, $entry->fresh()->status);

        $this->patch(route('teams.join-requests.respond', [$team->routeIdentifier(), $entry->id]), [
            'action' => 'block',
        ])->assertSessionHasErrorsIn('joinRequest'.$entry->id, 'review_reason');
        $this->assertSame(TeamJoinRequestStatusEnum::PENDING, $entry->fresh()->status);

        $reason = 'Повторные заявки после нарушения правил команды.';
        $this->patch(route('teams.join-requests.respond', [$team->routeIdentifier(), $entry->id]), [
            'action' => 'block',
            'review_reason' => $reason,
        ])->assertRedirect();
        $this->assertSame(TeamJoinRequestStatusEnum::BLOCKED, $entry->fresh()->status);
        $this->assertSame($reason, $entry->fresh()->review_reason);

        $this->actingAs($applicant)
            ->get(route('teams.show', $team->routeIdentifier()))
            ->assertOk()
            ->assertSee($reason);

        $this->actingAs($applicant)
            ->post(route('teams.join-requests.store', $team->routeIdentifier()))
            ->assertUnprocessable();

        $this->actingAs($owner)
            ->patch(route('teams.join-requests.respond', [$team->routeIdentifier(), $entry->id]), [
                'action' => 'unblock',
            ])->assertRedirect();
        $this->assertSame(TeamJoinRequestStatusEnum::REJECTED, $entry->fresh()->status);

        $this->actingAs($applicant)
            ->post(route('teams.join-requests.store', $team->routeIdentifier()))
            ->assertRedirect();
        $this->assertSame(TeamJoinRequestStatusEnum::PENDING, $entry->fresh()->status);
    }

    public function test_user_without_settings_permission_cannot_edit_team_settings(): void
    {
        $this->seed(GameLifecycleDemoSeeder::class);
        $outsider = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $team = Team::query()->where('alias', 'demo-red')->firstOrFail();

        $this->actingAs($outsider)
            ->get(route('teams.edit', $team->routeIdentifier()))
            ->assertForbidden();

        $this->patch(route('teams.settings.applications.update', $team->routeIdentifier()), [
            'accepts_join_requests' => 1,
        ])->assertForbidden();
    }
}
