<?php

namespace Tests\Feature\Venue;

use App\Modules\Contract\Domain\Enums\ContractStatusEnum;
use App\Modules\Contract\Domain\Models\ContractMembership;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Application\UseCases\ReviewVenueOwnershipClaimHandler;
use App\Modules\Venue\Application\UseCases\SubmitVenueOwnershipClaimHandler;
use App\Modules\Venue\Application\UseCases\UpdateVenueOwnershipStatusHandler;
use App\Modules\Venue\Domain\Enums\VenueOwnershipClaimStatusEnum;
use App\Modules\Venue\Domain\Enums\VenueOwnershipStatusEnum;
use App\Modules\Venue\Domain\Exceptions\VenueOwnershipClaimException;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\Venue\Domain\Models\VenueOwnership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class VenueOwnershipClaimWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_approve_claim_and_create_durable_ownership(): void
    {
        $applicant = $this->confirmedUser();
        $admin = $this->confirmedUser(UserSystemRoleEnum::ADMIN);
        $venue = Venue::factory()->create();
        $claim = app(SubmitVenueOwnershipClaimHandler::class)->handle(
            $venue,
            $applicant,
            'Я представитель площадки и могу подтвердить полномочия по запросу.',
        );

        $approved = app(ReviewVenueOwnershipClaimHandler::class)->approve($claim, $admin, 'Проверено.');
        $ownership = VenueOwnership::query()->where('source_claim_id', $approved->id)->sole();
        $membership = ContractMembership::query()->findOrFail($approved->owner_contract_membership_id);

        $this->assertSame(VenueOwnershipClaimStatusEnum::APPROVED, $approved->status);
        $this->assertSame(VenueOwnershipStatusEnum::ACTIVE, $ownership->status);
        $this->assertTrue($ownership->active_marker);
        $this->assertSame($applicant->id, $membership->user_id);
        $this->assertSame(ContractStatusEnum::ACTIVE, $membership->contract->status);
    }

    public function test_ownership_status_suspends_restores_and_revokes_owner_rights(): void
    {
        $applicant = $this->confirmedUser();
        $admin = $this->confirmedUser(UserSystemRoleEnum::ADMIN);
        $venue = Venue::factory()->create();
        $claim = app(SubmitVenueOwnershipClaimHandler::class)->handle($venue, $applicant, 'Корпоративные контакты и сведения о полномочиях.');
        app(ReviewVenueOwnershipClaimHandler::class)->approve($claim, $admin, 'Проверено.');
        $ownership = VenueOwnership::query()->where('source_claim_id', $claim->id)->sole();
        $handler = app(UpdateVenueOwnershipStatusHandler::class);

        $ownership = $handler->handle($ownership, VenueOwnershipStatusEnum::UNDER_REVIEW, 'Нужна повторная проверка.', $admin);
        $this->assertSame(ContractStatusEnum::INACTIVE, $ownership->contractMembership->contract->refresh()->status);
        $this->assertTrue($ownership->active_marker);

        $ownership = $handler->handle($ownership, VenueOwnershipStatusEnum::ACTIVE, 'Проверка завершена.', $admin);
        $this->assertSame(ContractStatusEnum::ACTIVE, $ownership->contractMembership->contract->refresh()->status);

        $ownership = $handler->handle($ownership, VenueOwnershipStatusEnum::REVOKED, 'Полномочия прекращены.', $admin);
        $this->assertSame(VenueOwnershipStatusEnum::REVOKED, $ownership->status);
        $this->assertNull($ownership->active_marker);
        $this->assertSame(ContractStatusEnum::INACTIVE, $ownership->contractMembership->contract->refresh()->status);
    }

    public function test_approval_closes_other_pending_claims_and_new_claim_is_server_rejected(): void
    {
        $first = $this->confirmedUser();
        $second = $this->confirmedUser();
        $admin = $this->confirmedUser(UserSystemRoleEnum::ADMIN);
        $venue = Venue::factory()->create();
        $submit = app(SubmitVenueOwnershipClaimHandler::class);
        $firstClaim = $submit->handle($venue, $first, 'Основания первого представителя и контакты.');
        $secondClaim = $submit->handle($venue, $second, 'Основания второго представителя и контакты.');

        app(ReviewVenueOwnershipClaimHandler::class)->approve($firstClaim, $admin, 'Проверено.');

        $this->assertSame(VenueOwnershipClaimStatusEnum::REJECTED, $secondClaim->refresh()->status);
        $this->expectException(VenueOwnershipClaimException::class);
        $submit->handle($venue, $second, 'Старая открытая форма не должна обойти проверку владельца.');
    }

    public function test_admin_can_view_claim_but_moderator_cannot_review_it(): void
    {
        $applicant = $this->confirmedUser();
        $admin = $this->confirmedUser(UserSystemRoleEnum::ADMIN);
        $moderator = $this->confirmedUser(UserSystemRoleEnum::MODERATOR);
        $venue = Venue::factory()->create();
        $claim = app(SubmitVenueOwnershipClaimHandler::class)->handle($venue, $applicant, 'Сведения о полномочиях представителя площадки.');

        $this->actingAs($admin)->get(route('account.venue-ownership.show', $claim))->assertOk();
        $this->actingAs($moderator)->get(route('account.venue-ownership.show', $claim))->assertForbidden();

        $this->expectException(VenueOwnershipClaimException::class);
        app(ReviewVenueOwnershipClaimHandler::class)->approve($claim, $moderator, 'Недостаточно прав.');
    }

    public function test_ownership_submission_does_not_depend_on_rental_feature(): void
    {
        config()->set('features.venue_rental.rental_flow', false);
        $claim = app(SubmitVenueOwnershipClaimHandler::class)->handle(
            Venue::factory()->create(),
            $this->confirmedUser(),
            'Подтверждающие сведения и корпоративные рабочие контакты.',
        );

        $this->assertSame(VenueOwnershipClaimStatusEnum::PENDING, $claim->status);
    }

    private function confirmedUser(UserSystemRoleEnum $role = UserSystemRoleEnum::USER): User
    {
        return User::factory()->create([
            'status' => UserStatusEnum::CONFIRMED,
            'system_role' => $role,
        ]);
    }
}
