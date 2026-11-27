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
