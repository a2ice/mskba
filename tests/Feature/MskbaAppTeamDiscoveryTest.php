<?php

namespace Tests\Feature;

use App\Modules\Contract\Domain\Enums\ContractFamilyEnum;
use App\Modules\Contract\Domain\Enums\ContractMembershipScopeTypeEnum;
use App\Modules\Contract\Domain\Enums\ContractStatusEnum;
use App\Modules\Contract\Domain\Enums\TeamMembershipAccessLevelEnum;
use App\Modules\Contract\Domain\Models\Contract;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleAssignerEnum;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Team\Domain\Enums\TeamInvitationStatusEnum;
use App\Modules\Team\Domain\Enums\TeamJoinRequestStatusEnum;
use App\Modules\Team\Domain\Enums\TeamStatusEnum;
use App\Modules\Team\Domain\Models\Team;
use App\Modules\Team\Domain\Models\TeamJoinRequest;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

final class MskbaAppTeamDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    private string $originalTheme;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalTheme = (string) config('themes.active');
        config()->set('themes.active', 'mskba_app');
        app()->forgetInstance(ThemeResolver::class);
        View::replaceNamespace('theme', resource_path('themes/mskba_app/views'));
    }

    protected function tearDown(): void
    {
        config()->set('themes.active', $this->originalTheme);
        app()->forgetInstance(ThemeResolver::class);
        View::replaceNamespace('theme', resource_path('themes/'.$this->originalTheme.'/views'));
        parent::tearDown();
    }

    private function participant(): User
    {
        return User::factory()->create([
            'status' => UserStatusEnum::CONFIRMED,
            'personal_data_distribution_required_at' => null,
        ]);
    }

    private function team(User $creator, string $name = 'Hoopers', bool $accepts = true): Team
    {
        $actor = Actor::factory()->create(['user_id' => $creator->id]);

        return Team::query()->create([
            'created_by_actor_id' => $actor->id,
            'name' => $name,
            'alias' => strtolower($name).'-'.$creator->id,
            'status' => TeamStatusEnum::ACTIVE,
            'accepts_join_requests' => $accepts,
        ]);
    }

    public function test_empty_my_teams_shows_three_panels_and_only_placeholder_action_buttons(): void
    {
        $user = $this->participant();
        $this->actingAs($user)->get(route('account.teams'))
            ->assertOk()
            ->assertSee('id="account-my-teams-title">Мои команды</h2>', false)
            ->assertSee('Найди команду по интересам и формату игры, отправь заявку на вступление или создай собственную.')
            ->assertSee('data-team-placeholder-action="find"', false)
            ->assertSee('data-team-placeholder-action="create"', false)
            ->assertSee('data-team-placeholder-dialog', false)
            ->assertSee('data-team-registration-gate', false)
            ->assertSee('Для продолжения необходимо завершить регистрацию.')
            ->assertSee('data-team-gate-confirm', false)
            ->assertSee('data-team-gate-cancel', false)
            ->assertSee('data-team-gate-help-trigger', false)
            ->assertSee('role="tooltip"', false)
            ->assertSee('aria-describedby="team-registration-gate-tip"', false)
            ->assertSee('id="account-team-invitations-title">Приглашения в команду</h2>', false)
            ->assertSee('Новых приглашений нет.')
            ->assertSee('id="account-team-applications-title">Заявки в команду</h2>', false)
            ->assertSee('Ты еще не подал ни одной заявки в команду.')
            ->assertSee('data-team-placeholder-action="find"', false)
            ->assertDontSee('View for page');
        $this->assertSame(2, substr_count(
            $this->actingAs($user)->get(route('account.teams'))->getContent(),
            'data-team-placeholder-action="find"',
        ));

        $this->get(route('teams.index'))->assertOk()->assertDontSee('View for page');
    }

    public function test_owned_team_displays_and_filtered_empty_result_is_distinct(): void
    {
        $user = $this->participant();
        $this->team($user, 'Court Friends');

        $this->actingAs($user)->get(route('account.teams'))
            ->assertOk()->assertSee('Court Friends')->assertDontSee('Пока своих команд нет.');

        $this->get(route('account.teams', ['condition' => 'pending']))
            ->assertOk()
            ->assertSee('По этим условиям команды не найдены')
            ->assertDontSee('Пока своих команд нет.');
    }

    public function test_pending_team_invitation_appears_only_in_invitations_panel_and_can_be_accepted(): void
    {
        $owner = $this->participant();
        $team = $this->team($owner, 'Inviting Hoopers');
        $candidate = $this->participant();
        $contract = Contract::query()->create([
            'family' => ContractFamilyEnum::MEMBERSHIP,
            'status' => ContractStatusEnum::INACTIVE,
            'assigner' => UserParticipationRoleAssignerEnum::USER,
        ]);
        $membership = $contract->membership()->create([
            'scope_type' => ContractMembershipScopeTypeEnum::TEAM,
            'scope_id' => $team->id,
            'user_id' => $candidate->id,
            'access_level' => TeamMembershipAccessLevelEnum::PLAYER,
            'invitation_status' => TeamInvitationStatusEnum::PENDING,
        ]);

        $page = $this->actingAs($candidate)->get(route('account.teams'))->assertOk();
        $page->assertSee('Найди команду по интересам и формату игры')
            ->assertSee('Inviting Hoopers')
            ->assertSee('Тебя пригласили вступить в эту команду.')
            ->assertSee('Принять')
            ->assertSee('Отклонить')
            ->assertSee(route('teams.invitations.respond', $membership->id), false);
        // No duplicate: team appears only once in content for an invited user.
        $this->assertSame(1, substr_count($page->getContent(), 'Inviting Hoopers'));

        $this->patch(route('teams.invitations.respond', $membership->id), ['decision' => 'accept'])
            ->assertRedirect();

        $this->get(route('account.teams'))->assertOk()
            ->assertSee('Inviting Hoopers')
            ->assertSee('Новых приглашений нет.')
            ->assertDontSee('Тебя пригласили вступить в эту команду.');
    }

    public function test_pending_invitations_of_others_are_not_visible_and_declined_disappear(): void
    {
        $owner = $this->participant();
        $team = $this->team($owner, 'Private Invite Team');
        $invited = $this->participant();
        $stranger = $this->participant();
        $contract = Contract::query()->create([
            'family' => ContractFamilyEnum::MEMBERSHIP,
            'status' => ContractStatusEnum::INACTIVE,
            'assigner' => UserParticipationRoleAssignerEnum::USER,
        ]);
        $membership = $contract->membership()->create([
            'scope_type' => ContractMembershipScopeTypeEnum::TEAM,
            'scope_id' => $team->id,
            'user_id' => $invited->id,
            'access_level' => TeamMembershipAccessLevelEnum::PLAYER,
            'invitation_status' => TeamInvitationStatusEnum::PENDING,
        ]);

        $this->actingAs($stranger)->get(route('account.teams'))->assertOk()
            ->assertSee('Новых приглашений нет.')
            ->assertDontSee('Private Invite Team');

        $this->actingAs($invited)
            ->patch(route('teams.invitations.respond', $membership->id), ['decision' => 'decline'])
            ->assertRedirect();

        $this->get(route('account.teams'))->assertOk()
            ->assertSee('Новых приглашений нет.')
            ->assertDontSee('Private Invite Team');
    }

    public function test_public_catalog_filters_and_team_detail_page_render(): void
    {
        $owner = $this->participant();
        $team = $this->team($owner, 'Rockets');

        $this->get(route('teams.index', ['q' => 'Rockets']))
            ->assertOk()->assertSee('Rockets')->assertSee('Посмотреть команду');
        $this->get(route('teams.index', ['q' => 'NoSuchName']))
            ->assertOk()->assertSee('По твоим фильтрам команд пока нет')->assertDontSee('View for page');

        $visitor = $this->participant();
        $this->actingAs($visitor)->get(route('teams.show', $team->routeIdentifier()))
            ->assertOk()
            ->assertSee('Подать заявку на вступление')
            ->assertDontSee('View for page');
    }

    public function test_existing_join_request_handler_is_used_and_pending_state_prevents_duplicate_cta(): void
    {
        $owner = $this->participant();
        $team = $this->team($owner, 'Eagles');
        $visitor = $this->participant();

        $this->actingAs($visitor)->post(route('teams.join-requests.store', $team->routeIdentifier()))
            ->assertRedirect();
        $this->assertDatabaseHas('team_join_requests', [
            'team_id' => $team->id,
            'user_id' => $visitor->id,
            'status' => TeamJoinRequestStatusEnum::PENDING->value,
        ]);

        $this->get(route('teams.show', $team->routeIdentifier()))
            ->assertOk()->assertSee('Твоя заявка ожидает решения команды.')
            ->assertDontSee('Подать заявку на вступление');

        $request = TeamJoinRequest::query()->where('team_id', $team->id)->where('user_id', $visitor->id)->firstOrFail();
        $request->update(['status' => TeamJoinRequestStatusEnum::BLOCKED]);
        $this->get(route('teams.show', $team->routeIdentifier()))
            ->assertOk()->assertSee('Заявки в эту команду для тебя заблокированы.')
            ->assertDontSee('Подать заявку на вступление');
    }

    public function test_my_own_join_applications_show_team_status_and_review_reason(): void
    {
        $owner = $this->participant();
        $team = $this->team($owner, 'Application Team');
        $candidate = $this->participant();

        $this->actingAs($candidate)->post(route('teams.join-requests.store', $team->routeIdentifier()))
            ->assertRedirect();

        $pending = $this->get(route('account.teams'))->assertOk();
        $pending->assertSee('Заявки в команду')
            ->assertSee('Application Team')
            ->assertSee('Ожидает решения')
            ->assertDontSee('Ты еще не подал ни одной заявки в команду.')
            ->assertSee(route('teams.show', $team->routeIdentifier()), false);

        $request = TeamJoinRequest::query()->where('team_id', $team->id)->where('user_id', $candidate->id)->firstOrFail();
        $request->update([
            'status' => TeamJoinRequestStatusEnum::REJECTED,
            'review_reason' => 'На этот сезон состав уже сформирован.',
        ]);

        $this->get(route('account.teams'))->assertOk()
            ->assertSee('Отклонена')
            ->assertSee('Ответ команды: На этот сезон состав уже сформирован.');

        $request->update(['status' => TeamJoinRequestStatusEnum::BLOCKED, 'review_reason' => 'Повторные заявки закрыты.']);
        $this->get(route('account.teams'))->assertOk()
            ->assertSee('Заблокирована')
            ->assertSee('Ответ команды: Повторные заявки закрыты.');

        $request->update(['status' => TeamJoinRequestStatusEnum::ACCEPTED, 'review_reason' => null]);
        $this->get(route('account.teams'))->assertOk()->assertSee('Принята');
    }

    public function test_submitted_applications_from_other_users_are_never_listed(): void
    {
        $owner = $this->participant();
        $team = $this->team($owner, 'Application Privacy Team');
        $applicant = $this->participant();
        $viewer = $this->participant();

        $this->actingAs($applicant)->post(route('teams.join-requests.store', $team->routeIdentifier()))
            ->assertRedirect();
        $this->actingAs($viewer)->get(route('account.teams'))->assertOk()
            ->assertSee('Ты еще не подал ни одной заявки в команду.')
            ->assertDontSee('Application Privacy Team');
    }

    public function test_create_team_screen_uses_existing_store_route(): void
    {
        $this->actingAs($this->participant())->get(route('teams.create'))
            ->assertOk()
            ->assertSee('Создать команду')
            ->assertSee(route('teams.store'), false)
            ->assertDontSee('View for page');
    }

    public function test_existing_team_store_handler_creates_and_redirects_to_a_real_app_detail_page(): void
    {
        $user = $this->participant();
        $this->actingAs($user)->post(route('teams.store'), [
            'name' => 'My New Hoopers',
            'sport_types' => ['basketball'],
        ])->assertRedirect();

        $team = Team::query()->where('name', 'My New Hoopers')->firstOrFail();
        $this->get(route('teams.show', $team->routeIdentifier()))
            ->assertOk()
            ->assertSee('My New Hoopers')
            ->assertSee('Ты уже состоишь в этой команде.');
    }
}
