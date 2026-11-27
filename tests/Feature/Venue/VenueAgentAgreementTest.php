<?php

namespace Tests\Feature\Venue;

use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum as Role;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Application\Services\VenueAgentAgreementService;
use App\Modules\Venue\Application\Services\VenueContractInventory;
use App\Modules\Venue\Domain\Enums\VenuePermissionEnum as Permission;
use App\Modules\Venue\Domain\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class VenueAgentAgreementTest extends TestCase
{
    use RefreshDatabase;

    private function user(Role $role = Role::USER): User
    {
        return User::factory()->create(['status' => UserStatusEnum::CONFIRMED, 'system_role' => $role]);
    }

    public function test_proposal_and_acceptance_freeze_terms_without_economic_or_acl_effects(): void
    {
        $venue = Venue::factory()->create();
        $admin = $this->user(Role::SUPERADMIN);
        $agent = $this->user();
        $service = app(VenueAgentAgreementService::class);
        $record = $service->propose($venue, $agent, $admin, 'percent', '5,25',
            [Permission::VIEW, Permission::VIEW_EVENT_INTERESTS], 'Согласованные условия сотрудничества.');
        $this->assertSame(525, $record->value);
        $this->assertSame($record->terms_hash, $record->calculatedTermsHash());
        $this->assertSame('proposed', $record->status);

        try {
            $service->accept($venue, $record, $agent, str_repeat('0', 64));
            $this->fail('Stale terms accepted.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('terms', $e->errors());
        }
        try {
            $service->accept($venue, $record, $this->user(), $record->terms_hash);
            $this->fail('Another identity accepted the agreement.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $accepted = $service->accept($venue, $record, $agent, $record->terms_hash);
        $this->assertSame('accepted', $accepted->status);
        $this->assertNotNull($accepted->accepted_at);
        $this->assertEquals($agent->id, $accepted->accepted_by_user_id);
        $this->assertTrue($accepted->accepted_at->equalTo($service->accept($venue, $record, $agent, $record->terms_hash)->accepted_at));
        $this->assertNull($accepted->contract_id);
        $this->assertNull($accepted->commission_agent_id);
        $this->assertDatabaseMissing('contract_memberships', ['scope_type' => 'venue', 'scope_id' => $venue->id, 'user_id' => $agent->id]);
        $this->assertDatabaseMissing('venue_commission_agents', ['venue_id' => $venue->id, 'user_id' => $agent->id]);
        $this->assertSame(1, app(VenueContractInventory::class)->agentAgreements($venue, $admin)->count());
        $closed = $service->close($venue, $accepted, $admin, 'Предложение отозвано сотрудником портала.');
        $this->assertSame('withdrawn', $closed->status);
        $this->assertNotNull($closed->accepted_at);
        $this->assertNotNull($closed->closed_at);
    }

    public function test_review_and_initial_activation_are_separate_and_protect_agreed_terms(): void
    {
        config()->set('features.venue_rental.rental_flow', true);
        $venue = Venue::factory()->create();
        $admin = $this->user(Role::SUPERADMIN);
        $agent = $this->user();
        $service = app(VenueAgentAgreementService::class);
        $record = $service->propose($venue, $agent, $admin, 'percent', '5,25',
            [Permission::VIEW, Permission::PROPOSE_EVENT_BOOKING], 'Согласованные условия сотрудничества.');
        $record = $service->accept($venue, $record, $agent, $record->terms_hash);
        try {
            $service->activate($venue, $record, $admin);
            $this->fail('Activation before review must fail.');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
        $record = $service->verify($venue, $record, $admin, 'Тестовый договор №123',
            'Проверены условия и договорное основание.');
        $this->assertSame('verified', $record->status);
        $this->assertDatabaseMissing('venue_commission_agents', ['venue_id' => $venue->id]);
        $this->assertDatabaseMissing('contract_memberships', ['scope_id' => $venue->id, 'scope_type' => 'venue', 'user_id' => $agent->id]);

        $record = $service->activate($venue, $record, $admin);
        $this->assertSame('active', $record->status);
        $this->assertTrue($record->active_marker);
        $this->assertSame($record->contract_id, $service->activate($venue, $record, $admin)->contract_id);
        $this->assertSame(525, $record->commissionAgent->value);
        $this->assertEquals($record->commission_agent_id, $venue->commissionPolicy()->first()->selected_agent_id);
        $this->assertTrue($record->contract->verification->isValid());
        $this->assertTrue(app(\App\Modules\Venue\Application\Services\VenueMembershipAccess::class)->allows($agent, $venue, Permission::PROPOSE_EVENT_BOOKING));
        $this->assertFalse(app(\App\Modules\Venue\Application\Services\VenueMembershipAccess::class)->allows($agent, $venue, Permission::CONFIRM_PAYMENTS));

        $commission = app(\App\Modules\VenueBooking\Application\Services\VenueCommissionManager::class);
        try {
            $commission->saveAgent($venue, $admin, $agent, 'percent', '10');
            $this->fail('Legacy rate editor must not overwrite agreed terms.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('user_id', $e->errors());
        }
        try {
            $commission->setVenue($venue, $admin, 'inherit', '0', null);
            $this->fail('Legacy selection must not remove active agreement.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('selected_agent_id', $e->errors());
        }
        $this->assertSame(525, $record->commissionAgent()->first()->value);
    }

    public function test_proposal_does_not_infer_financial_authority_or_allow_unsafe_permissions(): void
    {
        $venue = Venue::factory()->create();
        $admin = $this->user(Role::SUPERADMIN);
        $candidate = $this->user();
        $service = app(VenueAgentAgreementService::class);
        try {
            $service->propose($venue, $candidate, $this->user(Role::MANAGER), 'percent', '5', [], 'Согласованные условия сотрудничества.');
            $this->fail('Staff role alone cannot propose economic terms.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        try {
            $service->propose($venue, $candidate, $admin, 'percent', '5',
                [Permission::CONFIRM_PAYMENTS], 'Согласованные условия сотрудничества.');
            $this->fail('Payment confirmation must not be granted by this proposal.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('permissions', $e->errors());
        }
        $this->assertDatabaseCount('venue_agent_agreements', 0);
    }
}
