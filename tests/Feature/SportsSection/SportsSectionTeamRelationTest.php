<?php

namespace Tests\Feature\SportsSection;

use App\Modules\Event\Domain\Models\Event;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleAssignerEnum;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleEnum;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleStatusEnum;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\SportsSection\Application\UseCases\CreateSportsSectionHandler;
use App\Modules\SportsSection\Application\UseCases\ManageSectionCoachHandler;
use App\Modules\SportsSection\Domain\Enums\SportsSectionPermissionEnum;
use App\Modules\SportsSection\Domain\Models\SportsSection;
use App\Modules\Team\Application\Services\TeamManagementAccess;
use App\Modules\Team\Domain\Enums\TeamPermissionEnum;
use App\Modules\Team\Domain\Enums\TeamStatusEnum;
use App\Modules\Team\Domain\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class SportsSectionTeamRelationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('features.sports_sections.enabled', true);
    }

    public function test_many_to_many_links_and_unlink_keep_both_entities(): void
    {
        [$owner, $actor] = $this->roleUser(UserParticipationRoleEnum::COACH);
        $sectionA = $this->section($actor, 'Школа Химки');
        $sectionB = $this->section($actor, 'Летняя школа Химки');
        $team96 = $this->team($actor, 'Химки-96');
        $team98 = $this->team($actor, 'Химки-98');

        $this->actingAs($owner)
            ->post(route('account.sports-sections.teams.store', $sectionA), ['team_id' => $team96->id])
            ->assertRedirect()
            ->assertSessionHas('status');
        $this->post(route('account.sports-sections.teams.store', $sectionA), ['team_id' => $team98->id])
            ->assertRedirect()
            ->assertSessionHas('status');
        $this->post(route('account.sports-sections.teams.store', $sectionB), ['team_id' => $team96->id])
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertDatabaseCount('sports_section_team', 3);
        $this->assertCount(2, $sectionA->fresh()->teams);
        $this->assertCount(2, $team96->fresh()->sportsSections);

        $this->delete(route('account.sports-sections.teams.destroy', [$sectionA, $team96]))
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertDatabaseMissing('sports_section_team', [
            'sports_section_id' => $sectionA->id,
            'team_id' => $team96->id,
        ]);
        $this->assertDatabaseHas('sports_sections', ['id' => $sectionA->id]);
        $this->assertDatabaseHas('teams', ['id' => $team96->id]);
        $this->assertDatabaseHas('sports_section_team', [
            'sports_section_id' => $sectionB->id,
            'team_id' => $team96->id,
        ]);
    }

    public function test_link_requires_team_control_and_relation_does_not_expand_team_acl(): void
    {
        [$owner, $actor] = $this->roleUser(UserParticipationRoleEnum::COACH);
        [$otherOwner, $otherActor] = $this->roleUser(UserParticipationRoleEnum::COACH);
        [$sectionManager, $managerActor] = $this->roleUser(UserParticipationRoleEnum::COACH);
        $section = $this->section($actor, 'Секция ACL');
        $foreignTeam = $this->team($otherActor, 'Чужая команда');
        $ownedTeam = $this->team($actor, 'Команда владельца секции');

        $this->actingAs($owner)
            ->post(route('account.sports-sections.teams.store', $section), ['team_id' => $foreignTeam->id])
            ->assertRedirect()
            ->assertSessionHasErrors('section');
        $this->assertDatabaseMissing('sports_section_team', [
            'sports_section_id' => $section->id,
            'team_id' => $foreignTeam->id,
        ]);

        $this->post(route('account.sports-sections.teams.store', $section), ['team_id' => $ownedTeam->id])
            ->assertRedirect()
            ->assertSessionHas('status');

        app(ManageSectionCoachHandler::class)->add(
            $section,
            $sectionManager,
            $owner,
            [SportsSectionPermissionEnum::MANAGE],
        );

        $this->assertFalse(app(TeamManagementAccess::class)->allows(
            $ownedTeam,
            $managerActor,
            TeamPermissionEnum::EDIT_SETTINGS,
        ));

        $this->actingAs($sectionManager)
            ->delete(route('account.sports-sections.teams.destroy', [$section, $ownedTeam]))
            ->assertRedirect()
            ->assertSessionHas('status');
        $this->assertDatabaseMissing('sports_section_team', [
            'sports_section_id' => $section->id,
            'team_id' => $ownedTeam->id,
        ]);

        $this->actingAs($otherOwner)
            ->get(route('account.sports-sections.teams', $section))
            ->assertForbidden();
    }

    public function test_temporary_team_cannot_be_linked_and_is_not_offered_in_ui(): void
    {
        [$owner, $actor] = $this->roleUser(UserParticipationRoleEnum::COACH);
        [, $otherActor] = $this->roleUser(UserParticipationRoleEnum::COACH);
        $section = $this->section($actor, 'Секция постоянных команд');
        $permanent = $this->team($actor, 'Постоянная команда');
        $foreign = $this->team($otherActor, 'Недоступная команда');
        $temporary = $this->team($actor, 'Временная команда', Event::factory()->create()->id);

        $this->actingAs($owner)
            ->get(route('account.sports-sections.teams', $section))
            ->assertOk()
            ->assertSee($permanent->name)
            ->assertDontSee($foreign->name)
            ->assertDontSee($temporary->name);

        $this->post(route('account.sports-sections.teams.store', $section), ['team_id' => $temporary->id])
            ->assertRedirect()
            ->assertSessionHasErrors('section');
        $this->assertDatabaseMissing('sports_section_team', [
            'sports_section_id' => $section->id,
            'team_id' => $temporary->id,
        ]);
    }

    /** @return array{User, Actor} */
    private function roleUser(UserParticipationRoleEnum $role): array
    {
        $user = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $user->participationRoles(false)->create([
            'role' => $role,
            'status' => UserParticipationRoleStatusEnum::ACTIVE,
            'assigned_at' => now(),
            'assigned_by' => $user->id,
            'assigner' => UserParticipationRoleAssignerEnum::USER,
        ]);

        return [$user, app(CurrentActorResolver::class)->resolve($user, null)];
    }

    private function section(Actor $actor, string $name): SportsSection
    {
        return app(CreateSportsSectionHandler::class)->handle($actor, [
            'name' => $name,
            'description' => 'Регулярные тренировки.',
            'training_mode' => 'group',
            'game_format' => 'basketball',
            'pricing_type' => 'free',
            'single_session_price_minor' => null,
            'currency' => 'RUB',
            'contact_source' => 'head_coach',
        ]);
    }

    private function team(Actor $actor, string $name, ?int $eventId = null): Team
    {
        $normalized = Str::lower($name).'-'.Str::lower(Str::random(6));

        return Team::query()->create([
            'temporary_for_event_id' => $eventId,
            'created_by_actor_id' => $actor->id,
            'name' => $name,
            'base_name' => $name,
            'normalized_name' => $eventId === null ? $normalized : null,
            'name_sequence' => $eventId === null ? 1 : null,
            'alias' => Str::slug($name).'-'.Str::lower(Str::random(8)),
            'status' => TeamStatusEnum::ACTIVE,
        ]);
    }
}
