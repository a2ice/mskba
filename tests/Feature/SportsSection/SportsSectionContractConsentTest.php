<?php

namespace Tests\Feature\SportsSection;

use App\Modules\Contract\Application\Services\EntityContractManager;
use App\Modules\Contract\Domain\Enums\ContractMembershipScopeTypeEnum as Scope;
use App\Modules\Contract\Domain\Models\ContractMembership;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleAssignerEnum;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleEnum;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleStatusEnum;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\SportsSection\Application\Services\SportsSectionAccess;
use App\Modules\SportsSection\Application\UseCases\CreateSportsSectionHandler;
use App\Modules\SportsSection\Application\UseCases\ManageSectionCoachHandler;
use App\Modules\SportsSection\Domain\Enums\SportsSectionPermissionEnum as Right;
use App\Modules\SportsSection\Domain\Exceptions\SportsSectionException;
use App\Modules\SportsSection\Domain\Models\SportsSection;
use App\Modules\Team\Domain\Enums\TeamInvitationStatusEnum;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class SportsSectionContractConsentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('features.sports_sections.enabled', true);
    }

    /** @return array{User, Actor} */
    private function coach(): array
    {
        $user = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $user->participationRoles(false)->create([
            'role' => UserParticipationRoleEnum::COACH,
            'status' => UserParticipationRoleStatusEnum::ACTIVE,
            'assigned_at' => now(),
            'assigned_by' => $user->id,
            'assigner' => UserParticipationRoleAssignerEnum::USER,
        ]);

        return [$user, app(CurrentActorResolver::class)->resolve($user, null)];
    }

    private function section(Actor $actor): SportsSection
    {
        return app(CreateSportsSectionHandler::class)->handle($actor, [
            'name' => 'Секция контрактных приглашений',
            'description' => 'Тренировки по баскетболу',
            'training_mode' => 'group',
            'game_format' => 'basketball',
            'pricing_type' => 'free',
            'currency' => 'RUB',
            'contact_source' => 'head_coach',
        ]);
    }

    public function test_creator_has_accepted_unlimited_full_owner_contract(): void
    {
        [$owner, $actor] = $this->coach();
        $section = $this->section($actor);
        $membership = $section->headCoachMembership->load('contract.permissions');

        $this->assertSame('owner', $membership->access_level);
        $this->assertSame(['coach'], $membership->sport_roles);
        $this->assertSame(TeamInvitationStatusEnum::ACCEPTED, $membership->invitation_status);
        $this->assertNull($membership->contract->expires_at);
        $this->assertCount(count(Right::cases()), $membership->contract->permissions);
        $this->assertTrue(app(EntityContractManager::class)->isOwner($owner, Scope::SPORTS_SECTION, $section->id));
        $this->assertTrue(app(SportsSectionAccess::class)->allows($owner, $section, Right::MANAGE_COACHES));
    }

    public function test_pending_coach_cannot_manage_until_acceptance_and_contract_expires(): void
    {
        [$owner, $actor] = $this->coach();
        [$coach] = $this->coach();
        $section = $this->section($actor);
        $expiry = now()->addDays(2)->startOfMinute();
        $invitation = app(ManageSectionCoachHandler::class)->add(
            $section, $coach, $owner, [Right::MANAGE_SESSIONS],
            'Временный тренер', $expiry->toDateTimeString(), 'Вторая группа',
        );
        $this->assertSame('Временный тренер', $invitation->contract->name);
        $this->assertSame('Вторая группа', $invitation->contract->notes);
        $this->assertSame(TeamInvitationStatusEnum::PENDING, $invitation->invitation_status);
        $this->assertFalse(app(SportsSectionAccess::class)->allows($coach, $section, Right::MANAGE_SESSIONS));
        $this->actingAs($coach)->get(route('account.sports-sections.edit', $section))->assertForbidden();

        app(EntityContractManager::class)->respond($invitation, $coach, true);
        $this->assertTrue(app(SportsSectionAccess::class)->allows($coach, $section, Right::MANAGE_SESSIONS));
        $this->assertFalse(app(SportsSectionAccess::class)->allows($coach, $section, Right::MANAGE_COACHES));

        $this->travel(3)->days();
        $this->assertFalse(app(SportsSectionAccess::class)->allows($coach, $section, Right::MANAGE_SESSIONS));
        $this->assertFalse(app(EntityContractManager::class)->allows(
            $coach, Scope::SPORTS_SECTION, $section->id, Right::MANAGE_SESSIONS->value,
        ));
    }

    public function test_expanding_coach_rights_requires_reacceptance(): void
    {
        [$owner, $actor] = $this->coach();
        [$coach] = $this->coach();
        $section = $this->section($actor);
        $manager = app(ManageSectionCoachHandler::class);
        $invitation = $manager->add($section, $coach, $owner, [Right::MANAGE_SESSIONS]);
        app(EntityContractManager::class)->respond($invitation, $coach, true);
        $manager->permissions($section, $invitation, $owner, [Right::MANAGE_SESSIONS, Right::MANAGE_TRAINEES]);

        $this->assertSame(TeamInvitationStatusEnum::PENDING, $invitation->fresh()->invitation_status);
        $this->assertFalse(app(SportsSectionAccess::class)->allows($coach, $section, Right::MANAGE_SESSIONS));
        app(EntityContractManager::class)->respond($invitation, $coach, true);
        $this->assertTrue(app(SportsSectionAccess::class)->allows($coach, $section, Right::MANAGE_TRAINEES));
    }

    public function test_pending_coach_cannot_be_head_coach_and_current_head_cannot_be_revoked(): void
    {
        [$owner, $actor] = $this->coach();
        [$coach] = $this->coach();
        $section = $this->section($actor);
        $manager = app(ManageSectionCoachHandler::class);
        $invitation = $manager->add($section, $coach, $owner);
        try {
            $manager->transferHeadCoach($section, $invitation, $owner);
            $this->fail('Pending invitations must not become head coach.');
        } catch (SportsSectionException) {
            $this->assertNotSame($invitation->id, $section->fresh()->head_coach_membership_id);
        }

        app(EntityContractManager::class)->respond($invitation, $coach, true);
        $manager->transferHeadCoach($section, $invitation, $owner);
        $this->assertSame($invitation->id, $section->fresh()->head_coach_membership_id);

        $this->expectException(ValidationException::class);
        app(EntityContractManager::class)->revoke($invitation, $owner);
    }

    public function test_owner_transfer_is_not_enabled_without_section_specific_coach_flow(): void
    {
        [$owner, $actor] = $this->coach();
        [$recipient] = $this->coach();
        $section = $this->section($actor);

        $this->expectException(ValidationException::class);
        app(EntityContractManager::class)->transferOwner(
            Scope::SPORTS_SECTION, $section->id, $owner, $recipient,
        );
    }
}
