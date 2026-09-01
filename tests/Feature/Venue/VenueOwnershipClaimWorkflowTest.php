<?php

namespace Tests\Feature\Venue;

use App\Modules\Contract\Domain\Enums\ContractMembershipScopeTypeEnum;
use App\Modules\Contract\Domain\Enums\VenueMembershipAccessLevelEnum;
use App\Modules\Contract\Domain\Models\ContractMembership;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Application\UseCases\CancelVenueOwnershipClaimHandler;
use App\Modules\Venue\Application\UseCases\ReviewVenueOwnershipClaimHandler;
use App\Modules\Venue\Application\UseCases\SubmitVenueOwnershipClaimHandler;
use App\Modules\Venue\Domain\Enums\VenueOwnershipClaimStatusEnum;
use App\Modules\Venue\Domain\Enums\VenuePermissionEnum;
use App\Modules\Venue\Domain\Exceptions\VenueOwnershipClaimException;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\Venue\Domain\Models\VenueOwnershipClaim;
use App\Support\Features\FeatureDisabledException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class VenueOwnershipClaimWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('features.venue_rental.rental_flow', true);
    }

    public function test_confirmed_user_submits_only_one_active_claim_and_can_resubmit_after_cancellation(): void
    {
        $user = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $venue = Venue::factory()->create();
        $submit = app(SubmitVenueOwnershipClaimHandler::class);

        $claim = $submit->handle($venue, $user, 'Договор аренды и контакт управляющего.');

        $this->assertSame(VenueOwnershipClaimStatusEnum::PENDING, $claim->status);
        $this->assertTrue($claim->active_marker);

        $this->expectException(VenueOwnershipClaimException::class);
        $submit->handle($venue, $user, 'Повторная заявка.');
    }

    public function test_applicant_can_cancel_pending_claim_and_submit_a_new_one(): void
    {
        $user = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $venue = Venue::factory()->create();
        $submit = app(SubmitVenueOwnershipClaimHandler::class);
        $claim = $submit->handle($venue, $user, 'Документы владельца.');

        $cancelled = app(CancelVenueOwnershipClaimHandler::class)->handle($claim, $user);
        $replacement = $submit->handle($venue, $user, 'Обновлённые документы владельца.');

        $this->assertSame(VenueOwnershipClaimStatusEnum::CANCELLED, $cancelled->status);
        $this->assertNull($cancelled->active_marker);
        $this->assertSame(VenueOwnershipClaimStatusEnum::PENDING, $replacement->status);
    }

    public function test_superadmin_approval_atomically_creates_owner_membership_and_permission_snapshot(): void
    {
        $applicant = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $reviewer = User::factory()->create([
            'status' => UserStatusEnum::CONFIRMED,
            'system_role' => UserSystemRoleEnum::SUPERADMIN,
        ]);
        $venue = Venue::factory()->create();
        $claim = app(SubmitVenueOwnershipClaimHandler::class)->handle($venue, $applicant, 'Документы.');

        $approved = app(ReviewVenueOwnershipClaimHandler::class)->approve($claim, $reviewer, 'Проверено.');

        $membership = ContractMembership::query()->findOrFail($approved->owner_contract_membership_id);
        $this->assertSame(VenueOwnershipClaimStatusEnum::APPROVED, $approved->status);
        $this->assertSame(ContractMembershipScopeTypeEnum::VENUE, $membership->scope_type);
        $this->assertSame(VenueMembershipAccessLevelEnum::OWNER->value, $membership->access_level);
        $this->assertSame($applicant->id, $membership->user_id);
        $this->assertEqualsCanonicalizing(
            array_column(VenuePermissionEnum::cases(), 'value'),
            $membership->contract->permissions()->pluck('permission')->all(),
        );

        $repeated = app(ReviewVenueOwnershipClaimHandler::class)->approve($approved, $reviewer);
        $this->assertSame($membership->id, $repeated->owner_contract_membership_id);
        $this->assertDatabaseCount('contract_memberships', 1);
        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $applicant->id,
            'title' => 'Владение площадкой подтверждено',
        ]);
    }

    public function test_self_approval_and_second_owner_are_rejected_without_partial_membership(): void
    {
        $firstApplicant = User::factory()->create([
            'status' => UserStatusEnum::CONFIRMED,
            'system_role' => UserSystemRoleEnum::SUPERADMIN,
        ]);
        $secondApplicant = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $reviewer = User::factory()->create([
            'status' => UserStatusEnum::CONFIRMED,
            'system_role' => UserSystemRoleEnum::SUPERADMIN,
        ]);
        $venue = Venue::factory()->create();
        $submit = app(SubmitVenueOwnershipClaimHandler::class);
        $review = app(ReviewVenueOwnershipClaimHandler::class);
        $selfClaim = $submit->handle($venue, $firstApplicant, 'Документы первого заявителя.');
        $secondClaim = $submit->handle($venue, $secondApplicant, 'Документы второго заявителя.');

        try {
            $review->approve($selfClaim, $firstApplicant);
            $this->fail('Self-approval must be rejected.');
        } catch (VenueOwnershipClaimException) {
            $this->assertDatabaseCount('contract_memberships', 0);
        }

        $review->approve($selfClaim, $reviewer);

        try {
            $review->approve($secondClaim, $reviewer);
            $this->fail('A second active owner must be rejected.');
        } catch (VenueOwnershipClaimException) {
            $this->assertDatabaseCount('contract_memberships', 1);
            $this->assertSame(VenueOwnershipClaimStatusEnum::PENDING, $secondClaim->refresh()->status);
        }
    }

    public function test_unconfirmed_user_cannot_submit_and_disabled_flag_blocks_application_handler(): void
    {
        $user = User::factory()->create(['status' => UserStatusEnum::UNCONFIRMED]);
        $venue = Venue::factory()->create();
        $submit = app(SubmitVenueOwnershipClaimHandler::class);

        try {
            $submit->handle($venue, $user, 'Документы.');
            $this->fail('Unconfirmed user must not submit a claim.');
        } catch (VenueOwnershipClaimException) {
            $this->assertDatabaseCount('venue_ownership_claims', 0);
        }

        config()->set('features.venue_rental.rental_flow', false);

        $this->expectException(FeatureDisabledException::class);
        $submit->handle($venue, $user, 'Документы.');
    }

    public function test_superadmin_can_reject_claim_with_reason(): void
    {
        $applicant = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $reviewer = User::factory()->create([
            'status' => UserStatusEnum::CONFIRMED,
            'system_role' => UserSystemRoleEnum::SUPERADMIN,
        ]);
        $venue = Venue::factory()->create();
        $claim = app(SubmitVenueOwnershipClaimHandler::class)->handle($venue, $applicant, 'Документы.');

        $rejected = app(ReviewVenueOwnershipClaimHandler::class)->reject($claim, $reviewer, 'Недостаточно подтверждений.');

        $this->assertSame(VenueOwnershipClaimStatusEnum::REJECTED, $rejected->status);
        $this->assertSame('Недостаточно подтверждений.', $rejected->decision_reason);
        $this->assertSame($reviewer->id, $rejected->reviewer_user_id);
        $this->assertDatabaseCount('contract_memberships', 0);
    }

    public function test_http_routes_are_hidden_when_disabled_and_expose_claim_flow_when_enabled(): void
    {
        $applicant = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $reviewer = User::factory()->create([
            'status' => UserStatusEnum::CONFIRMED,
            'system_role' => UserSystemRoleEnum::SUPERADMIN,
        ]);
        $venue = Venue::factory()->create();

        config()->set('features.venue_rental.rental_flow', false);
        $this->actingAs($applicant)
            ->getJson(route('venues.ownership-claims.create', $venue))
            ->assertNotFound()
            ->assertJsonPath('code', 'feature_disabled');

        config()->set('features.venue_rental.rental_flow', true);
        config()->set('audit.ignore_console', false);
        $this->actingAs($applicant)
            ->get(route('venues.ownership-claims.create', $venue))
            ->assertOk();
        $this->actingAs($applicant)
            ->post(route('venues.ownership-claims.store', $venue), [
                'evidence' => 'Контракт и контакты управляющей организации.',
            ])
            ->assertRedirect();

        $claim = $venue->ownershipClaims()->firstOrFail();
        $this->actingAs($reviewer)
            ->get(route('admin.venue-ownership-claims.index'))
            ->assertOk();
        $this->actingAs($reviewer)
            ->post(route('admin.venue-ownership-claims.approve', $claim), ['reason' => 'Проверено.'])
            ->assertRedirect();

        $this->assertSame(VenueOwnershipClaimStatusEnum::APPROVED, $claim->refresh()->status);
        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => VenueOwnershipClaim::class,
            'auditable_id' => $claim->id,
            'event' => 'updated',
        ]);
    }

    public function test_regular_admin_cannot_view_or_review_ownership_evidence(): void
    {
        $applicant = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $admin = User::factory()->create([
            'status' => UserStatusEnum::CONFIRMED,
            'system_role' => UserSystemRoleEnum::ADMIN,
        ]);
        $venue = Venue::factory()->create();
        $claim = app(SubmitVenueOwnershipClaimHandler::class)->handle($venue, $applicant, 'Документы владельца площадки.');

        $this->actingAs($admin)
            ->get(route('admin.venue-ownership-claims.index'))
            ->assertForbidden();
        $this->actingAs($admin)
            ->post(route('admin.venue-ownership-claims.approve', $claim))
            ->assertForbidden();
        $this->actingAs($admin)
            ->get(route('account.venue-ownership-claims.show', $claim))
            ->assertForbidden();
    }
}
