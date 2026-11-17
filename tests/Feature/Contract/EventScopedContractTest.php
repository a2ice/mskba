<?php

namespace Tests\Feature\Contract;

use App\Modules\Contract\Application\Services\EntityContractManager;
use App\Modules\Contract\Domain\Enums\ContractMembershipScopeTypeEnum as Scope;
use App\Modules\Contract\Domain\Models\ContractMembership;
use App\Modules\Event\Application\Services\EventManagementAccess;
use App\Modules\Event\Domain\Enums\EventResponsibilityPermissionEnum as Right;
use App\Modules\Event\Domain\Enums\EventResponsibilityStatusEnum;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class EventScopedContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_event_issues_owner_contract_and_game_uses_parent_event(): void
    {
        $organizer = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $actor = app(CurrentActorResolver::class)->resolve($organizer, null);
        $event = Event::factory()->create(['organizer_actor_id' => $actor->id]);

        $owner = ContractMembership::query()->with('contract.permissions')
            ->where('scope_type', Scope::EVENT->value)
            ->where('scope_id', $event->id)
            ->where('access_level', 'owner')->firstOrFail();

        $this->assertSame($organizer->id, $owner->user_id);
        $this->assertSame('accepted', $owner->invitation_status->value);
        $this->assertTrue(app(EventManagementAccess::class)->allows($event, $actor, Right::MANAGE_MINI_GAME_SCORE));
    }

    public function test_event_participation_and_legacy_permission_rows_do_not_grant_management(): void
    {
        $organizer = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $candidate = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $event = Event::factory()->create([
            'organizer_actor_id' => app(CurrentActorResolver::class)->resolve($organizer, null)->id,
        ]);

        $participant = $event->participants()->create([
            'user_id' => $candidate->id,
            'role' => 'participant',
            'status' => 'confirmed',
            'responsibility_status' => EventResponsibilityStatusEnum::ACCEPTED,
        ]);
        $participant->responsibilityPermissions()->create(['permission' => Right::MANAGE_PARTICIPANTS->value]);
        $access = app(EventManagementAccess::class);
        $actor = app(CurrentActorResolver::class)->resolve($candidate, null);
        $this->assertFalse($access->allows($event, $actor, Right::MANAGE_PARTICIPANTS));
    }

    public function test_delegated_event_contract_requires_acceptance_and_expires(): void
    {
        $owner = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $delegate = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $event = Event::factory()->create([
            'organizer_actor_id' => app(CurrentActorResolver::class)->resolve($owner, null)->id,
        ]);
        $contracts = app(EntityContractManager::class);
        $membership = $contracts->invite(Scope::EVENT, $event->id, $owner, $delegate,
            'Помощник организатора', [Right::UPDATE_EVENT->value]);

        $access = app(EventManagementAccess::class);
        $actor = app(CurrentActorResolver::class)->resolve($delegate, null);
        $this->assertFalse($access->allows($event, $actor, Right::UPDATE_EVENT));
        $contracts->respond($membership, $delegate, true);
        $this->assertTrue($access->allows($event, $actor, Right::UPDATE_EVENT));
        $this->assertFalse($access->allows($event, $actor, Right::MANAGE_FUNDS));

        $membership->contract->update(['expires_at' => now()->subMinute()]);
        $this->assertFalse($access->allows($event, $actor, Right::UPDATE_EVENT));
    }
}
