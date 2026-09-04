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
        $this->assertNotEmpty($claim->public_id);

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
            'title' => 'Управление площадкой подтверждено',
        ]);
    }

    public function test_self_approval_is_rejected_and_other_pending_claims_close_after_owner_is_confirmed(): void
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

        $this->assertDatabaseCount('contract_memberships', 1);
        $this->assertSame(VenueOwnershipClaimStatusEnum::REJECTED, $secondClaim->refresh()->status);
        $this->assertSame(
            'Управление площадкой подтверждено по другой заявке.',
            $secondClaim->decision_reason,
        );
    }

    public function test_unverified_user_cannot_submit_but_ownership_handler_does_not_depend_on_rental_feature(): void
    {
        $user = User::factory()->create(['status' => UserStatusEnum::UNCONFIRMED]);
        $venue = Venue::factory()->create();
        $submit = app(SubmitVenueOwnershipClaimHandler::class);

        try {
            $submit->handle($venue, $user, 'Документы.');
            $this->fail('Unverified user must not submit a claim.');
        } catch (VenueOwnershipClaimException) {
            $this->assertDatabaseCount('venue_ownership_claims', 0);
        }

        config()->set('features.venue_rental.rental_flow', false);
        $confirmed = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $claim = $submit->handle($venue, $confirmed, 'Подтверждающие документы и рабочие контакты.');

        $this->assertSame(VenueOwnershipClaimStatusEnum::PENDING, $claim->status);
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

    public function test_public_management_flow_is_available_independently_from_rental_feature(): void
    {
        $applicant = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $reviewer = User::factory()->create([
            'status' => UserStatusEnum::CONFIRMED,
            'system_role' => UserSystemRoleEnum::SUPERADMIN,
        ]);
        $venue = Venue::factory()->create();

        config()->set('features.venue_rental.rental_flow', false);
        config()->set('audit.ignore_console', false);

        $this->get(route('venues.management', $venue))
            ->assertOk();

        $this->actingAs($applicant)
            ->post(route('venues.management.claim', $venue), [
                'evidence' => 'Контракт и контакты управляющей организации.',
            ])
            ->assertRedirect();

        $claim = $venue->ownershipClaims()->firstOrFail();
        $this->actingAs($reviewer)
            ->get(route('account.venue-ownership.show', $claim))
            ->assertOk();
        $this->actingAs($reviewer)
            ->post(route('account.venue-ownership.approve', $claim), ['reason' => 'Проверено.'])
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
            ->get(route('account.venue-ownership.show', $claim))
            ->assertForbidden();
        $this->actingAs($admin)
            ->post(route('account.venue-ownership.approve', $claim))
            ->assertRedirect();
        $this->assertSame(VenueOwnershipClaimStatusEnum::PENDING, $claim->refresh()->status);
    }
}
