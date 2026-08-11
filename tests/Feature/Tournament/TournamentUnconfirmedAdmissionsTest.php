<?php

namespace Tests\Feature\Tournament;

use App\Modules\Event\Domain\Enums\GameFormatEnum;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Notification\Domain\Enums\UserNotificationStatusEnum;
use App\Modules\Notification\Domain\Models\UserNotification;
use App\Modules\Tournament\Domain\Enums\TournamentAdmissionStatusEnum;
use App\Modules\Tournament\Domain\Enums\TournamentRecruitmentModeEnum;
use App\Modules\Tournament\Domain\Models\Tournament;
use App\Modules\Tournament\Domain\Models\TournamentAdmission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class TournamentUnconfirmedAdmissionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_gets_auth_cta_and_authenticated_user_gets_role_picker(): void
    {
        [$tournament] = $this->createTournament();
        auth()->logout();

        $this->get(route('tournaments.show', $tournament->routeIdentifier()))
            ->assertOk()
            ->assertSee('Подать заявку')
            ->assertSee('data-modal-target="auth-entry-classic"', false)
            ->assertSee('data-auth-redirect-url="'.route('tournaments.show', $tournament->routeIdentifier(), false).'"', false)
            ->assertDontSee('В качестве кого?');

        $player = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $this->actingAs($player)->get(route('tournaments.show', $tournament->routeIdentifier()))
            ->assertOk()
            ->assertSee('data-modal-target="tournament-application-role"', false)
            ->assertSee('В качестве кого?')
            ->assertSee('class="form-toggle__input" type="checkbox"', false)
            ->assertSeeInOrder(['Игрок', 'Тренер', 'Менеджер']);
    }

    public function test_individual_application_requires_supported_role(): void
    {
        [$tournament] = $this->createTournament();
        $player = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);

        $this->actingAs($player)
            ->post(route('tournaments.admissions.apply', $tournament->routeIdentifier()), ['roles' => ['referee']])
            ->assertSessionHasErrors('roles.0');

        $this->assertDatabaseCount('tournament_admissions', 0);
    }

    public function test_setting_defaults_to_false_and_is_normalized_for_preformed_teams(): void
    {
        $owner = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);

        $this->actingAs($owner)->post(route('tournaments.store'), $this->payload());
        $tournament = Tournament::query()->firstOrFail();
        $this->assertFalse($tournament->accepts_unconfirmed_participants);

        $this->actingAs($owner)->put(route('tournaments.update', $tournament->routeIdentifier()), [
            ...$this->payload(),
            'accepts_unconfirmed_participants' => true,
        ])->assertSessionHas('status');
        $this->assertTrue($tournament->fresh()->accepts_unconfirmed_participants);

        $this->actingAs($owner)->put(route('tournaments.update', $tournament->routeIdentifier()), [
            ...$this->payload(TournamentRecruitmentModeEnum::PREFORMED_TEAMS),
            'accepts_unconfirmed_participants' => true,
        ])->assertSessionHas('status');
        $this->assertFalse($tournament->fresh()->accepts_unconfirmed_participants);
    }

    public function test_unconfirmed_application_is_rejected_without_admission_or_notification_when_setting_is_off(): void
    {
        [$tournament, $owner] = $this->createTournament();
        $player = User::factory()->create(['status' => UserStatusEnum::UNCONFIRMED]);

        $this->actingAs($player)
            ->post(route('tournaments.admissions.apply', $tournament->routeIdentifier()), ['roles' => ['player']])
            ->assertSessionHas('error', 'По условиям этого турнира для подачи заявки необходимо подтвердить аккаунт');

        $this->assertDatabaseCount('tournament_admissions', 0);
        $this->assertDatabaseMissing('user_notifications', [
            'user_id' => $owner->id,
            'title' => 'Новая заявка на турнир',
        ]);
        $this->actingAs($player)->get(route('tournaments.show', $tournament->routeIdentifier()))
            ->assertSee('По условиям этого турнира для подачи заявки необходимо подтвердить аккаунт');
    }

    public function test_unconfirmed_application_succeeds_and_notifies_organizer_when_setting_is_on(): void
    {
        [$tournament, $owner] = $this->createTournament(true);
        $player = User::factory()->create(['status' => UserStatusEnum::UNCONFIRMED]);

        $this->actingAs($player)
            ->post(route('tournaments.admissions.apply', $tournament->routeIdentifier()), ['roles' => ['player', 'coach']])
            ->assertSessionHas('status', 'Заявка отправлена.');

        $admission = TournamentAdmission::query()->firstOrFail();
        $this->assertSame($player->id, $admission->user_id);
        $this->assertSame(['player', 'coach'], $admission->roles->map->value->all());
        $this->assertSame(TournamentAdmissionStatusEnum::PENDING, $admission->status);
        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $owner->id,
            'title' => 'Новая заявка на турнир',
        ]);
    }

    public function test_confirmed_application_and_unconfirmed_invitation_ignore_setting(): void
    {
        [$tournament, $owner] = $this->createTournament();
        $confirmed = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $invited = User::factory()->create(['status' => UserStatusEnum::UNCONFIRMED]);

        $this->actingAs($confirmed)
            ->post(route('tournaments.admissions.apply', $tournament->routeIdentifier()), ['roles' => ['manager']])
            ->assertSessionHas('status');
        $this->actingAs($owner)
            ->post(route('tournaments.admissions.invite', $tournament->routeIdentifier()), ['user_id' => $invited->id])
            ->assertSessionHas('status');

        $invitation = TournamentAdmission::query()->where('user_id', $invited->id)->firstOrFail();
        $invitationNotification = UserNotification::query()
            ->where('user_id', $invited->id)
            ->where('payload->source', 'tournament.invitation.created')
            ->firstOrFail();
        $this->actingAs($invited)->post(route('tournaments.admissions.respond', [
            $tournament->routeIdentifier(),
            $invitation,
        ]), ['decision' => TournamentAdmissionStatusEnum::ACCEPTED->value])->assertSessionHas('status');

        $this->assertSame(TournamentAdmissionStatusEnum::ACCEPTED, $invitation->fresh()->status);
        $this->assertSame(UserNotificationStatusEnum::READ, $invitationNotification->fresh()->status);
    }

    public function test_application_decision_closes_organizer_notification_notifies_applicant_and_hides_cta(): void
    {
        [$tournament, $owner] = $this->createTournament();
        $player = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);

        $this->actingAs($player)->post(
            route('tournaments.admissions.apply', $tournament->routeIdentifier()),
            ['roles' => ['player', 'coach']],
        );
        $admission = TournamentAdmission::query()->firstOrFail();
        $organizerNotification = UserNotification::query()
            ->where('user_id', $owner->id)
            ->where('payload->source', 'tournament.application.submitted')
            ->firstOrFail();

        $this->actingAs($owner)->post(
            route('tournaments.admissions.respond', [$tournament->routeIdentifier(), $admission]),
            ['decision' => TournamentAdmissionStatusEnum::ACCEPTED->value],
        )->assertSessionHas('status');

        $organizerNotification->refresh();
        $this->assertSame(UserNotificationStatusEnum::READ, $organizerNotification->status);
        $this->assertNotNull($organizerNotification->read_at);
        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $player->id,
            'status' => UserNotificationStatusEnum::NEW->value,
            'title' => 'Заявка на турнир принята',
        ]);

        $this->actingAs($player)->get(route('tournaments.show', $tournament->routeIdentifier()))
            ->assertOk()
            ->assertDontSee('data-tournament-application-cta=', false)
            ->assertDontSee('data-modal="tournament-application-role"', false);
    }

    /** @return array{Tournament, User} */
    private function createTournament(bool $acceptsUnconfirmed = false): array
    {
        $owner = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $this->actingAs($owner)->post(route('tournaments.store'), [
            ...$this->payload(),
            'accepts_unconfirmed_participants' => $acceptsUnconfirmed,
        ]);

        return [Tournament::query()->firstOrFail(), $owner];
    }

    /** @return array<string, mixed> */
    private function payload(TournamentRecruitmentModeEnum $mode = TournamentRecruitmentModeEnum::INDIVIDUAL_DRAFT): array
    {
        return [
            'title' => 'Balanced Cup',
            'alias' => 'balanced-cup',
            'starts_on' => today()->addWeek()->format('Y-m-d'),
            'ends_on' => today()->addWeeks(2)->format('Y-m-d'),
            'format' => GameFormatEnum::STREETBALL_3X3->value,
            'recruitment_mode' => $mode->value,
        ];
    }
}
