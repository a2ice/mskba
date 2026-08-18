<?php

namespace Tests\Feature\Tournament;

use App\Modules\Contract\Domain\Enums\ContractFamilyEnum;
use App\Modules\Contract\Domain\Enums\ContractMembershipScopeTypeEnum;
use App\Modules\Contract\Domain\Enums\ContractStatusEnum;
use App\Modules\Contract\Domain\Models\Contract;
use App\Modules\Event\Domain\Enums\GameFormatEnum;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleAssignerEnum;
use App\Modules\Identity\Domain\Enums\UserRegistrationChannelEnum;
use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Team\Domain\Enums\TeamInvitationStatusEnum;
use App\Modules\Tournament\Domain\Enums\TournamentAdmissionSourceEnum;
use App\Modules\Tournament\Domain\Enums\TournamentAdmissionStatusEnum;
use App\Modules\Tournament\Domain\Enums\TournamentPermissionEnum;
use App\Modules\Tournament\Domain\Enums\TournamentRecruitmentModeEnum;
use App\Modules\Tournament\Domain\Models\Tournament;
use App\Modules\Tournament\Domain\Models\TournamentAdmission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class TournamentOnSiteRegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_check_in_page_offers_existing_account_login_without_submitting_application(): void
    {
        $tournament = Tournament::factory()->create([
            'format' => GameFormatEnum::STREETBALL_3X3,
            'recruitment_mode' => TournamentRecruitmentModeEnum::INDIVIDUAL_DRAFT,
            'allows_on_site_registration' => true,
        ]);

        $this->get(route('tournaments.on-site.show', $tournament->routeIdentifier()))
            ->assertOk()
            ->assertSee('Войти в существующий аккаунт')
            ->assertSee('Или зарегистрируйтесь быстро')
            ->assertSee('data-modal-target="auth-entry-classic"', false)
            ->assertSee(route('tournaments.on-site.show', $tournament->routeIdentifier(), false), false);

        $this->assertDatabaseCount('tournament_admissions', 0);
    }

    public function test_closed_check_in_page_identifies_tournament_organizer(): void
    {
        $organizer = User::factory()->create(['username' => 'tournament-organizer']);
        $organizer->profile()->create(['first_name' => 'Иван', 'last_name' => 'Иванов']);
        $actor = Actor::factory()->create(['user_id' => $organizer->id]);
        $tournament = Tournament::factory()->create([
            'created_by_actor_id' => $actor->id,
            'format' => GameFormatEnum::STREETBALL_3X3,
            'recruitment_mode' => TournamentRecruitmentModeEnum::INDIVIDUAL_DRAFT,
            'allows_on_site_registration' => false,
        ]);

        $this->get(route('tournaments.on-site.show', $tournament->routeIdentifier()))
            ->assertOk()
            ->assertSee('Регистрация на месте закрыта')
            ->assertSee('Иван Иванов')
            ->assertSee('@tournament-organizer');
    }

    public function test_guest_can_register_and_apply_while_on_site_registration_is_enabled(): void
    {
        $tournament = Tournament::factory()->create([
            'format' => GameFormatEnum::STREETBALL_3X3,
            'recruitment_mode' => TournamentRecruitmentModeEnum::INDIVIDUAL_DRAFT,
            'allows_on_site_registration' => true,
        ]);

        $response = $this->post(route('tournaments.on-site.store', $tournament->routeIdentifier()), [
            'username' => 'WalkIn.Player',
            'roles' => ['player', 'coach'],
            'privacy_consent' => '1',
        ]);

        $user = User::query()->where('username', 'walkin.player')->firstOrFail();
        $response->assertRedirect(route('tournaments.on-site.show', $tournament->routeIdentifier()));
        $this->assertAuthenticatedAs($user);
        $this->assertSame(UserRegistrationChannelEnum::TOURNAMENT_ON_SITE, $user->registration_channel);
        $this->assertNull($user->password);
        $this->assertFalse($user->is_temporary_password);
        $this->assertDatabaseHas('tournament_admissions', [
            'tournament_id' => $tournament->id,
            'user_id' => $user->id,
            'source' => TournamentAdmissionSourceEnum::ON_SITE->value,
            'status' => 'pending',
        ]);
        $this->assertDatabaseHas('user_participation_roles', ['user_id' => $user->id, 'role' => 'player', 'status' => 'active']);
        $this->assertDatabaseHas('user_consents', ['user_id' => $user->id, 'source' => 'tournament_on_site_registration']);
    }

    public function test_authenticated_user_can_apply_through_on_site_page(): void
    {
        $user = User::factory()->create(['username' => 'player-01']);
        $tournament = Tournament::factory()->create([
            'format' => GameFormatEnum::STREETBALL_3X3,
            'recruitment_mode' => TournamentRecruitmentModeEnum::INDIVIDUAL_DRAFT,
            'allows_on_site_registration' => true,
        ]);

        $this->actingAs($user)
            ->post(route('tournaments.on-site.store', $tournament->routeIdentifier()), [
                'roles' => ['player', 'coach'],
            ])
            ->assertRedirect(route('tournaments.on-site.show', $tournament->routeIdentifier()))
            ->assertSessionHas('status');

        $this->assertDatabaseHas('tournament_admissions', [
            'tournament_id' => $tournament->id,
            'user_id' => $user->id,
            'source' => TournamentAdmissionSourceEnum::ON_SITE->value,
            'status' => 'pending',
        ]);
        $this->actingAs($user)->get(route('tournaments.on-site.show', $tournament->routeIdentifier()))
            ->assertOk()
            ->assertSee('После обработки заявки вы получите уведомление, а результат отобразится на этой странице.')
            ->assertDontSee('Открыть турнир');
    }

    public function test_canonical_user_cannot_repeat_application_created_by_alias(): void
    {
        $canonical = User::factory()->create();
        $alias = User::factory()->create(['canonical_user_id' => $canonical->id]);
        $tournament = Tournament::factory()->create([
            'format' => GameFormatEnum::STREETBALL_3X3,
            'recruitment_mode' => TournamentRecruitmentModeEnum::INDIVIDUAL_DRAFT,
            'allows_on_site_registration' => true,
        ]);
        TournamentAdmission::query()->create([
            'tournament_id' => $tournament->id,
            'candidate_type' => 'user',
            'user_id' => $alias->id,
            'direction' => 'application',
            'source' => TournamentAdmissionSourceEnum::ON_SITE,
            'roles' => ['player'],
            'status' => TournamentAdmissionStatusEnum::PENDING,
            'requested_by_actor_id' => Actor::factory()->create(['user_id' => $alias->id])->id,
        ]);

        $this->actingAs($canonical)
            ->post(route('tournaments.on-site.store', $tournament->routeIdentifier()), ['roles' => ['player']])
            ->assertSessionHas('error', 'У вас уже есть активная заявка на этот турнир.');

        $this->assertSame(1, $tournament->admissions()->count());
        $this->actingAs($canonical)
            ->get(route('tournaments.on-site.show', $tournament->routeIdentifier()))
            ->assertOk()
            ->assertSee('После обработки заявки вы получите уведомление, а результат отобразится на этой странице.');
    }

    public function test_on_site_application_notifies_owner_and_active_game_manager_with_refreshing_url(): void
    {
        $owner = User::factory()->create();
        $ownerActor = Actor::factory()->create(['user_id' => $owner->id]);
        $manager = User::factory()->create();
        $applicant = User::factory()->create();
        $tournament = Tournament::factory()->create([
            'created_by_actor_id' => $ownerActor->id,
            'format' => GameFormatEnum::STREETBALL_3X3,
            'recruitment_mode' => TournamentRecruitmentModeEnum::INDIVIDUAL_DRAFT,
            'allows_on_site_registration' => true,
        ]);
        $contract = Contract::query()->create(['family' => ContractFamilyEnum::MEMBERSHIP, 'name' => 'Ответственный', 'status' => ContractStatusEnum::ACTIVE, 'starts_at' => now(), 'assigned_at' => now(), 'assigner' => UserParticipationRoleAssignerEnum::USER]);
        $contract->permissions()->create(['permission' => TournamentPermissionEnum::MANAGE_GAMES->value]);
        $contract->membership()->create([
            'scope_type' => ContractMembershipScopeTypeEnum::TOURNAMENT,
            'scope_id' => $tournament->id,
            'user_id' => $manager->id,
            'access_level' => 'responsible',
            'sport_roles' => [],
            'invitation_status' => TeamInvitationStatusEnum::ACCEPTED,
        ]);

        $this->actingAs($applicant)->post(route('tournaments.on-site.store', $tournament->routeIdentifier()), ['roles' => ['player']]);

        $admissionId = (int) $tournament->admissions()->where('user_id', $applicant->id)->value('id');
        $expectedUrl = route('tournaments.manage', ['tournament' => $tournament->routeIdentifier(), 'admission' => $admissionId], false).'#participants';
        $this->assertDatabaseHas('user_notifications', ['user_id' => $owner->id, 'title' => 'Регистрация на месте', 'action_url' => $expectedUrl]);
        $this->assertDatabaseHas('user_notifications', ['user_id' => $manager->id, 'title' => 'Регистрация на месте', 'action_url' => $expectedUrl]);
    }

    public function test_closed_on_site_registration_rejects_submission_without_creating_user(): void
    {
        $tournament = Tournament::factory()->create([
            'format' => GameFormatEnum::STREETBALL_3X3,
            'recruitment_mode' => TournamentRecruitmentModeEnum::INDIVIDUAL_DRAFT,
            'allows_on_site_registration' => false,
        ]);

        $this->from(route('tournaments.on-site.show', $tournament->routeIdentifier()))
            ->post(route('tournaments.on-site.store', $tournament->routeIdentifier()), [
                'username' => 'walkin-player',
                'roles' => ['player'],
                'privacy_consent' => '1',
            ])->assertRedirect()->assertSessionHas('error');

        $this->assertDatabaseMissing('users', ['username' => 'walkin-player']);
    }

    public function test_owner_can_accept_on_site_application_and_applicant_sees_persistent_success(): void
    {
        [$owner, $tournament, $applicant, $admission] = $this->pendingApplication();

        $this->actingAs($owner)->post(route('tournaments.admissions.respond', [$tournament->routeIdentifier(), $admission]), [
            'decision' => TournamentAdmissionStatusEnum::ACCEPTED->value,
        ])->assertSessionHas('status');

        $this->assertDatabaseHas('user_notifications', ['user_id' => $applicant->id, 'title' => 'Заявка на турнир принята']);
        $this->actingAs($applicant)->get(route('tournaments.on-site.show', $tournament->routeIdentifier()))
            ->assertOk()->assertSee('Ваша заявка принята.')->assertSee('Открыть турнир');
    }

    public function test_owner_can_decline_and_applicant_can_retry(): void
    {
        [$owner, $tournament, $applicant, $admission] = $this->pendingApplication();

        $this->actingAs($owner)->post(route('tournaments.admissions.respond', [$tournament->routeIdentifier(), $admission]), [
            'decision' => TournamentAdmissionStatusEnum::DECLINED->value,
            'response_comment' => 'Заполните игровой профиль.',
        ])->assertSessionHas('status');

        $this->assertDatabaseHas('tournament_admissions', ['id' => $admission->id, 'response_comment' => 'Заполните игровой профиль.']);
        $this->assertDatabaseHas('user_notifications', ['user_id' => $applicant->id, 'title' => 'Заявка на турнир отклонена']);
        $this->actingAs($applicant)->get(route('tournaments.on-site.show', $tournament->routeIdentifier()))
            ->assertOk()->assertSee('Заявка отклонена.')->assertSee('Заполните игровой профиль.')->assertSee('Вы можете отправить заявку повторно.')->assertSee('Отправить заявку');
    }

    public function test_declined_application_without_comment_displays_reason_not_specified(): void
    {
        [$owner, $tournament, $applicant, $admission] = $this->pendingApplication();

        $this->actingAs($owner)->post(route('tournaments.admissions.respond', [$tournament->routeIdentifier(), $admission]), [
            'decision' => TournamentAdmissionStatusEnum::DECLINED->value,
        ])->assertSessionHas('status');

        $this->actingAs($applicant)->get(route('tournaments.on-site.show', $tournament->routeIdentifier()))
            ->assertOk()->assertSee('Причина:</strong> не указана', false);
    }

    public function test_owner_can_block_repeat_on_site_registration_for_tournament(): void
    {
        [$owner, $tournament, $applicant, $admission] = $this->pendingApplication();

        $this->actingAs($owner)->post(route('tournaments.admissions.block-on-site', [$tournament->routeIdentifier(), $admission]), [
            'response_comment' => 'Повторные заявки после личного отказа.',
        ])
            ->assertSessionHas('status');

        $this->assertDatabaseHas('tournament_admissions', ['id' => $admission->id, 'status' => 'declined', 'response_comment' => 'Повторные заявки после личного отказа.']);
        $this->assertNotNull($admission->refresh()->blocked_at);
        $this->actingAs($applicant)->get(route('tournaments.on-site.show', $tournament->routeIdentifier()))
            ->assertOk()->assertSee('Повторная регистрация заблокирована.')->assertSee('Повторные заявки после личного отказа.')->assertDontSee('Отправить заявку');
        $this->actingAs($applicant)->post(route('tournaments.on-site.store', $tournament->routeIdentifier()), ['roles' => ['player']])
            ->assertSessionHas('error', 'Повторная регистрация для вашего аккаунта заблокирована. Обратитесь к организатору турнира.');
    }

    /** @return array{User, Tournament, User, TournamentAdmission} */
    private function pendingApplication(): array
    {
        $owner = User::factory()->create();
        $ownerActor = Actor::factory()->create(['user_id' => $owner->id]);
        $applicant = User::factory()->create();
        $tournament = Tournament::factory()->create([
            'created_by_actor_id' => $ownerActor->id,
            'format' => GameFormatEnum::STREETBALL_3X3,
            'recruitment_mode' => TournamentRecruitmentModeEnum::INDIVIDUAL_DRAFT,
            'allows_on_site_registration' => true,
        ]);
        $this->actingAs($applicant)->post(route('tournaments.on-site.store', $tournament->routeIdentifier()), ['roles' => ['player']]);

        return [$owner, $tournament, $applicant, $tournament->admissions()->where('user_id', $applicant->id)->firstOrFail()];
    }
}
