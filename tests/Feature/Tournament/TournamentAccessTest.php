<?php

namespace Tests\Feature\Tournament;

use App\Modules\Contract\Domain\Enums\ContractStatusEnum;
use App\Modules\Contract\Domain\Models\ContractMembership;
use App\Modules\Event\Domain\Enums\GameFormatEnum;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Team\Domain\Enums\TeamInvitationStatusEnum;
use App\Modules\Tournament\Domain\Enums\TournamentPermissionEnum;
use App\Modules\Tournament\Domain\Enums\TournamentStatusEnum;
use App\Modules\Tournament\Domain\Models\Tournament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class TournamentAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_permission_works_only_after_acceptance_and_only_for_its_scope(): void
    {
        [$owner, $staff, $tournament] = $this->createTournamentAndUsers();
        $otherTournament = Tournament::factory()->create();

        $this->actingAs($owner)
            ->getJson(route('tournaments.staff.candidates', [$tournament->routeIdentifier(), 'q' => 'staff']))
            ->assertOk()
            ->assertJsonFragment(['id' => $staff->id, 'meta' => '@'.$staff->username]);

        $this->actingAs($owner)->post(route('tournaments.staff.invite', $tournament->routeIdentifier()), [
            'user_id' => $staff->id,
            'permissions' => [TournamentPermissionEnum::MANAGE_DESCRIPTION->value],
        ])->assertSessionHas('status');
        $membership = ContractMembership::query()->firstOrFail();
        $this->assertSame(TeamInvitationStatusEnum::PENDING, $membership->invitation_status);
        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $staff->id,
            'title' => 'Приглашение в команду турнира',
        ]);
        $this->actingAs($staff)->get(route('tournaments.manage', $tournament->routeIdentifier()))
            ->assertOk()->assertSee('Приглашение');

        $this->actingAs($staff)->put(route('tournaments.update', $tournament->routeIdentifier()), $this->payload('Pending'))
            ->assertSessionHas('error', 'У вас нет права управлять этим турниром.');

        $this->actingAs($staff)->post(route('tournaments.staff.respond', [$tournament->routeIdentifier(), $membership]), [
            'decision' => TeamInvitationStatusEnum::ACCEPTED->value,
        ])->assertSessionHas('status');
        $this->actingAs($staff)->get(route('tournaments.manage', $tournament->routeIdentifier()))
            ->assertOk()->assertSee('Описание и обложка')->assertDontSee('Удалить турнир');

        $this->actingAs($staff)->put(route('tournaments.update', $tournament->routeIdentifier()), $this->payload('Delegated title', 'Новое описание'))
            ->assertSessionHas('status');
        $this->assertSame('Кубок Москвы', $tournament->fresh()->title);
        $this->assertSame('Новое описание', $tournament->fresh()->short_description);

        $this->actingAs($staff)->patch(route('tournaments.status', $tournament->routeIdentifier()), [
            'status' => TournamentStatusEnum::CANCELLED->value,
        ])->assertSessionHas('error');
        $this->assertSame(TournamentStatusEnum::CONFIRMED, $tournament->fresh()->status);

        $this->actingAs($staff)->put(route('tournaments.update', $otherTournament->routeIdentifier()), $this->payload('Other'))
            ->assertSessionHas('error');
    }

    public function test_revoked_or_inactive_contract_immediately_loses_permission(): void
    {
        [$owner, $staff, $tournament] = $this->createTournamentAndUsers();
        $this->actingAs($owner)->post(route('tournaments.staff.invite', $tournament->routeIdentifier()), [
            'user_id' => $staff->id,
            'permissions' => [TournamentPermissionEnum::MANAGE_STATUS->value],
        ]);
        $membership = ContractMembership::query()->firstOrFail();
        $this->actingAs($staff)->post(route('tournaments.staff.respond', [$tournament->routeIdentifier(), $membership]), [
            'decision' => TeamInvitationStatusEnum::ACCEPTED->value,
        ]);

        $this->actingAs($staff)->patch(route('tournaments.status', $tournament->routeIdentifier()), [
            'status' => TournamentStatusEnum::UNCONFIRMED->value,
        ])->assertSessionHas('status');

        $this->actingAs($owner)->delete(route('tournaments.staff.revoke', [$tournament->routeIdentifier(), $membership]))
            ->assertSessionHas('status');
        $this->assertSame(TeamInvitationStatusEnum::REVOKED, $membership->fresh()->invitation_status);
        $this->assertSame(ContractStatusEnum::INACTIVE, $membership->contract->fresh()->status);

        $this->actingAs($staff)->patch(route('tournaments.status', $tournament->routeIdentifier()), [
            'status' => TournamentStatusEnum::CONFIRMED->value,
        ])->assertSessionHas('error');
        $this->assertSame(TournamentStatusEnum::UNCONFIRMED, $tournament->fresh()->status);
    }

    /** @return array{User, User, Tournament} */
    private function createTournamentAndUsers(): array
    {
        $owner = User::factory()->create(['username' => 'owner', 'status' => UserStatusEnum::CONFIRMED]);
        $staff = User::factory()->create(['username' => 'staff', 'status' => UserStatusEnum::CONFIRMED]);
        $this->actingAs($owner)->post(route('tournaments.store'), $this->payload());

        return [$owner, $staff, Tournament::query()->firstOrFail()];
    }

    /** @return array<string, mixed> */
    private function payload(string $title = 'Кубок Москвы', string $short = 'Описание'): array
    {
        return [
            'title' => $title,
            'alias' => 'moscow-cup',
            'starts_on' => today()->addWeek()->format('Y-m-d'),
            'ends_on' => today()->addWeeks(2)->format('Y-m-d'),
            'short_description' => $short,
            'full_description' => 'Полное описание',
            'format' => GameFormatEnum::STREETBALL_3X3->value,
        ];
    }
}
