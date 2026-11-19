<?php

namespace Tests\Feature\Invitation;

use App\Modules\Contract\Domain\Models\ContractMembership;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Invitation\Domain\Models\AccountInvitation;
use App\Modules\Venue\Application\Services\VenueUserRestrictionService;
use App\Modules\Venue\Application\UseCases\ReviewVenueOwnershipClaimHandler;
use App\Modules\Venue\Domain\Enums\VenueOwnershipClaimStatusEnum as ClaimStatus;
use App\Modules\Venue\Domain\Enums\VenueUserRestrictionTypeEnum;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\Venue\Domain\Models\VenueOwnershipClaim;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class OwnerVenueContractCaseTest extends TestCase
{
    use RefreshDatabase;

    private function scenario(): array
    {
        $issuer = User::factory()->create([
            'status' => UserStatusEnum::CONFIRMED,
            'system_role' => UserSystemRoleEnum::SUPERADMIN,
        ]);
        $user = User::factory()->create([
            'status' => UserStatusEnum::CONFIRMED,
            'system_role' => UserSystemRoleEnum::USER,
        ]);
        $venue = Venue::factory()->create(['name' => 'Тестовый зал приглашения']);
        $invitation = AccountInvitation::query()->create([
            'status' => 'pending',
            'progress' => 'awaiting_ownership',
            'views_count' => 0,
            'public_id' => (string) Str::uuid(),
            'token_hash' => hash('sha256', random_bytes(32)),
            'contact_type' => 'email',
            'contact_value' => 'owner@example.test',
            'scope_type' => 'venue',
            'scope_id' => $venue->id,
            'role' => 'owner',
            'title' => 'Владение тестовым залом',
            'expires_at' => now()->addDays(7),
            'created_by_user_id' => $issuer->id,
            'accepted_user_id' => $user->id,
            'contact_verified_at' => now(),
        ]);
        return [$issuer, $user, $venue, $invitation];
    }

    public function test_owner_invitation_links_to_account_case_and_is_visible_before_contract(): void
    {
        [, $user, $venue] = $this->scenario();
        $this->actingAs($user)->get(route('account.contracts.venues'))
            ->assertOk()->assertSee('Тестовый зал приглашения')
            ->assertSee('Требуется подтверждение');

        $this->get(route('account.venue-contracts.show', $venue))
            ->assertOk()->assertSee('Начать подтверждение управления');
        $this->get(route('venues.management', $venue))
            ->assertRedirect(route('account.venue-contracts.show', $venue));
        $this->assertDatabaseCount('venue_ownership_claims', 0);
    }

    public function test_draft_submission_approval_and_contract_open_without_auto_owner(): void
    {
        [$issuer, $user, $venue] = $this->scenario();
        $this->actingAs($user)->post(route('account.venue-contracts.start', $venue))
            ->assertRedirect();
        $claim = VenueOwnershipClaim::query()->where('venue_id', $venue->id)->sole();
        $this->assertSame(ClaimStatus::DRAFT, $claim->status);
        $this->assertDatabaseCount('contract_memberships', 0);

        $this->get(route('account.venue-contracts.show', $venue))
            ->assertOk()->assertSee('Отправить на рассмотрение');
        $this->post(route('account.venue-ownership.draft.save', $claim), [
            'intent' => 'submit',
            'ownership_evidence' => 'Представляю спорткомплекс по договору управления и отвечаю за расписание.',
        ])->assertRedirect(route('account.venue-contracts.show', $venue));
        $this->assertSame(ClaimStatus::PENDING, $claim->refresh()->status);

        app(ReviewVenueOwnershipClaimHandler::class)->approve($claim, $issuer, 'Документы проверены');
        $this->actingAs($user)->get(route('account.venue-contracts.show', $venue))
            ->assertOk()->assertSee('Подтверждено')->assertSee('Назначенные контракты');
        $this->assertSame(1, ContractMembership::query()
            ->where('scope_type', 'venue')->where('scope_id', $venue->id)
            ->where('access_level', 'owner')->count());
    }

    public function test_rejection_allows_new_draft_but_blocked_claim_prevents_retry(): void
    {
        [$issuer, $user, $venue] = $this->scenario();
        $this->actingAs($user)->post(route('account.venue-contracts.start', $venue))->assertRedirect();
        $first = VenueOwnershipClaim::query()->where('venue_id', $venue->id)->sole();

        $this->post(route('account.venue-ownership.draft.save', $first), [
            'intent' => 'submit',
            'ownership_evidence' => 'Могу подтвердить полномочия у директора спорткомплекса.',
        ])->assertRedirect();
        app(ReviewVenueOwnershipClaimHandler::class)->reject($first->refresh(), $issuer, 'Предоставьте больше сведений.');
        $this->get(route('account.venue-contracts.show', $venue))
            ->assertOk()->assertSee('Подать повторную заявку');
        $this->post(route('account.venue-contracts.start', $venue))->assertRedirect();
        $second = VenueOwnershipClaim::query()->where('venue_id', $venue->id)->latest('id')->firstOrFail();
        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(ClaimStatus::DRAFT, $second->status);

        $this->post(route('account.venue-ownership.draft.save', $second), [
            'intent' => 'submit',
            'ownership_evidence' => 'Прилагаю уточнённые полномочия и сведения организации.',
        ])->assertRedirect();
        app(ReviewVenueOwnershipClaimHandler::class)->block($second->refresh(), $issuer, 'Подача необоснованных заявок.');
        app(VenueUserRestrictionService::class)->impose(
            $venue, $user, VenueUserRestrictionTypeEnum::OWNERSHIP_CLAIM,
            'Подача необоснованных заявок.', $issuer,
        );
        $this->get(route('account.venue-contracts.show', $venue))
            ->assertOk()->assertSee('Заблокировано')
            ->assertDontSee('Подать повторную заявку');
        $this->post(route('account.venue-contracts.start', $venue))->assertForbidden();
        $restriction = app(VenueUserRestrictionService::class)->active(
            $venue, $user, VenueUserRestrictionTypeEnum::OWNERSHIP_CLAIM
        );
        app(VenueUserRestrictionService::class)->revoke($restriction, $issuer, 'Разобрались');
        $this->post(route('account.venue-contracts.start', $venue))->assertRedirect();
        $this->assertDatabaseCount('venue_ownership_claims', 3);
    }

    public function test_invalid_evidence_returns_to_case_not_last_loaded_js_asset(): void
    {
        [, $user, $venue] = $this->scenario();
        $this->actingAs($user)->post(route('account.venue-contracts.start', $venue))->assertRedirect();
        $claim = VenueOwnershipClaim::query()->where('venue_id', $venue->id)->sole();
        $caseUrl = route('account.venue-contracts.show', [
            'venue' => $venue->id, 'claim' => $claim->public_id,
        ]);

        $this->withSession([
            '_previous' => ['url' => url('/assets/design-themes/court_day/example.js')],
        ])->post(route('account.venue-ownership.draft.save', $claim), [
            'intent' => 'submit',
            'ownership_evidence' => 'short',
        ])->assertRedirect($caseUrl)->assertSessionHasErrors('ownership_evidence');

        $this->assertSame(ClaimStatus::DRAFT, $claim->refresh()->status);
    }

    public function test_unrelated_account_cannot_read_or_start_other_users_case(): void
    {
        [, $owner, $venue] = $this->scenario();
        $other = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $this->actingAs($other)->get(route('account.venue-contracts.show', $venue))->assertForbidden();
        $this->post(route('account.venue-contracts.start', $venue))->assertForbidden();
        $this->actingAs($owner)->get(route('account.venue-contracts.show', $venue))->assertOk();
    }
}
