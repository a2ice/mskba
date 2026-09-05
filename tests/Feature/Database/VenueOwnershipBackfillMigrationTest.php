<?php

namespace Tests\Feature\Database;

use App\Modules\Contract\Domain\Enums\ContractFamilyEnum;
use App\Modules\Contract\Domain\Enums\ContractStatusEnum;
use App\Modules\Contract\Domain\Enums\VenueMembershipAccessLevelEnum;
use App\Modules\Contract\Domain\Models\Contract;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleAssignerEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Domain\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class VenueOwnershipBackfillMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_backfill_materializes_only_current_active_membership_owner_contracts(): void
    {
        $currentVenue = Venue::factory()->create();
        $futureVenue = Venue::factory()->create();
        $expiredVenue = Venue::factory()->create();
        $wrongFamilyVenue = Venue::factory()->create();

        $currentMembershipId = $this->ownerMembership($currentVenue, now()->subDay(), now()->addDay());
        $this->ownerMembership($futureVenue, now()->addDay(), null);
        $this->ownerMembership($expiredVenue, now()->subDays(2), now()->subDay());
        $this->ownerMembership($wrongFamilyVenue, now()->subDay(), now()->addDay(), ContractFamilyEnum::RELATION);

        Schema::dropIfExists('venue_user_restrictions');
        Schema::dropIfExists('venue_ownership_documents');
        Schema::dropIfExists('venue_ownerships');

        $migration = require database_path('migrations/2026_09_05_123000_create_venue_ownerships_and_restrictions.php');
        $migration->up();

        $this->assertDatabaseCount('venue_ownerships', 1);
        $this->assertDatabaseHas('venue_ownerships', [
            'venue_id' => $currentVenue->id,
            'contract_membership_id' => $currentMembershipId,
            'status' => 'active',
            'active_marker' => true,
        ]);
        $this->assertDatabaseMissing('venue_ownerships', ['venue_id' => $futureVenue->id]);
        $this->assertDatabaseMissing('venue_ownerships', ['venue_id' => $expiredVenue->id]);
        $this->assertDatabaseMissing('venue_ownerships', ['venue_id' => $wrongFamilyVenue->id]);
    }

    private function ownerMembership(
        Venue $venue,
        mixed $startsAt,
        mixed $expiresAt,
        ContractFamilyEnum $family = ContractFamilyEnum::MEMBERSHIP,
    ): int {
        $user = User::factory()->create();
        $contract = Contract::query()->create([
            'family' => $family,
            'status' => ContractStatusEnum::ACTIVE,
            'starts_at' => $startsAt,
            'expires_at' => $expiresAt,
            'assigner' => UserParticipationRoleAssignerEnum::OTHER,
        ]);

        return (int) $contract->membership()->create([
            'scope_type' => 'venue',
            'scope_id' => $venue->id,
            'user_id' => $user->id,
            'access_level' => VenueMembershipAccessLevelEnum::OWNER,
        ])->id;
    }
}
