<?php

namespace Tests\Feature\SportsSection;

use App\Modules\Event\Domain\Enums\EventStatusEnum;
use App\Modules\Event\Domain\Enums\EventTypeEnum;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleAssignerEnum;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleEnum;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleStatusEnum;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\SportsSection\Application\UseCases\CreateSportsSectionHandler;
use App\Modules\SportsSection\Application\UseCases\CreateTrainingSessionHandler;
use App\Modules\SportsSection\Application\UseCases\LinkTrainingSessionEventHandler;
use App\Modules\SportsSection\Application\UseCases\ManageSectionCoachHandler;
use App\Modules\SportsSection\Application\UseCases\ManageSectionTraineeHandler;
use App\Modules\SportsSection\Application\UseCases\ManageSportsSectionContactHandler;
use App\Modules\SportsSection\Application\UseCases\ManageTrainingSessionSnapshotHandler;
use App\Modules\SportsSection\Application\UseCases\TransitionTrainingSessionHandler;
use App\Modules\SportsSection\Domain\Enums\SportsSectionAccessLevelEnum;
use App\Modules\SportsSection\Domain\Enums\SportsSectionPermissionEnum;
use App\Modules\SportsSection\Domain\Enums\TrainingSessionStatusEnum;
use App\Modules\SportsSection\Domain\Exceptions\SportsSectionException;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\Venue\Domain\Models\VenueCourt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class SportsSectionDomainTest extends TestCase
{
    use RefreshDatabase;

    public function test_confirmed_coach_creates_section_as_owner_and_head_coach(): void
    {
        [$coach, $actor] = $this->roleUser(UserParticipationRoleEnum::COACH);
        $section = app(CreateSportsSectionHandler::class)->handle($actor, $this->sectionData());

        $membership = $section->headCoachMembership;
        $this->assertSame($coach->id, $membership->user_id);
        $this->assertSame(SportsSectionAccessLevelEnum::OWNER->value, $membership->access_level);
        $this->assertSame(['coach'], $membership->sport_roles);
        $this->assertCount(count(SportsSectionPermissionEnum::cases()), $membership->contract->permissions);
    }

    public function test_unconfirmed_or_non_coach_cannot_create_section(): void
    {
        [$player, $actor] = $this->roleUser(UserParticipationRoleEnum::PLAYER);
        $this->expectException(SportsSectionException::class);
        app(CreateSportsSectionHandler::class)->handle($actor, $this->sectionData());
    }

    public function test_owner_adds_coach_controls_permissions_and_safely_transfers_head_coach(): void
    {
        [$owner, $actor] = $this->roleUser(UserParticipationRoleEnum::COACH);
        [$coach] = $this->roleUser(UserParticipationRoleEnum::COACH);
        $section = app(CreateSportsSectionHandler::class)->handle($actor, $this->sectionData());
        $handler = app(ManageSectionCoachHandler::class);
        $membership = $handler->add($section, $coach, $owner, [SportsSectionPermissionEnum::MANAGE_SESSIONS]);

        $this->assertDatabaseHas('contract_permissions', ['contract_id' => $membership->contract_id, 'permission' => SportsSectionPermissionEnum::MANAGE_SESSIONS->value]);
        $handler->transferHeadCoach($section, $membership, $owner);
        $this->assertSame($membership->id, $section->refresh()->head_coach_membership_id);

        $this->expectException(SportsSectionException::class);
        $handler->remove($section, $membership, $owner);
    }

    public function test_trainee_membership_is_deactivated_without_destroying_history(): void
    {
        [$owner, $actor] = $this->roleUser(UserParticipationRoleEnum::COACH);
        [$player] = $this->roleUser(UserParticipationRoleEnum::PLAYER);
        $section = app(CreateSportsSectionHandler::class)->handle($actor, $this->sectionData());
        $handler = app(ManageSectionTraineeHandler::class);
        $membership = $handler->activate($section, $player, $owner, 'Новичок');
        $handler->deactivate($section, $membership, $owner, 'Пауза');

        $this->assertDatabaseHas('section_trainee_memberships', ['id' => $membership->id, 'status' => 'inactive', 'status_reason' => 'Пауза']);
        $this->assertNotNull($membership->refresh()->left_at);
    }

    public function test_session_inherits_place_and_snapshots_current_people_and_price(): void
    {
        [$owner, $actor] = $this->roleUser(UserParticipationRoleEnum::COACH);
        [$player] = $this->roleUser(UserParticipationRoleEnum::PLAYER);
        $venue = Venue::factory()->create();
        $court = $this->court($venue);
        $section = app(CreateSportsSectionHandler::class)->handle($actor, $this->sectionData([
            'pricing_type' => 'paid', 'single_session_price_minor' => 150000,
            'primary_venue_id' => $venue->id, 'primary_venue_court_id' => $court->id,
        ]));
        app(ManageSectionTraineeHandler::class)->activate($section, $player, $owner);
        $session = app(CreateTrainingSessionHandler::class)->handle($section, $actor, [
            'starts_at' => now()->addDay(), 'ends_at' => now()->addDay()->addHour(),
        ]);

        $this->assertSame($venue->id, $session->venue_id);
        $this->assertSame($court->id, $session->venue_court_id);
        $this->assertSame(150000, $session->effectivePriceMinor());
        $this->assertSame([$player->id], $session->participants->pluck('user_id')->all());
        $this->assertContains($owner->id, $session->coaches->pluck('user_id')->all());

        $section->update(['single_session_price_minor' => 200000]);
        app(TransitionTrainingSessionHandler::class)->handle($section, $session, $actor, TrainingSessionStatusEnum::CONFIRMED);
        $this->assertSame(200000, $session->refresh()->confirmed_price_minor);
        $section->update(['single_session_price_minor' => 300000]);
        $this->assertSame(200000, $session->refresh()->effectivePriceMinor());

        $override = app(CreateTrainingSessionHandler::class)->handle($section, $actor, [
            'starts_at' => now()->addDays(2), 'ends_at' => now()->addDays(2)->addHour(),
            'price_override_minor' => 99000,
        ]);
        $this->assertSame(99000, $override->effectivePriceMinor());
    }

    public function test_session_snapshot_can_change_without_changing_section_roster(): void
    {
        [$owner, $actor] = $this->roleUser(UserParticipationRoleEnum::COACH);
        [$first] = $this->roleUser(UserParticipationRoleEnum::PLAYER);
        [$second] = $this->roleUser(UserParticipationRoleEnum::PLAYER);
        $section = app(CreateSportsSectionHandler::class)->handle($actor, $this->sectionData());
        app(ManageSectionTraineeHandler::class)->activate($section, $first, $owner);
        app(ManageSectionTraineeHandler::class)->activate($section, $second, $owner);
        $session = app(CreateTrainingSessionHandler::class)->handle($section, $actor, ['starts_at' => now()->addDay(), 'ends_at' => now()->addDay()->addHour()]);
        $snapshot = app(ManageTrainingSessionSnapshotHandler::class);
        $snapshot->removeParticipant($section, $session, $session->participants->firstWhere('user_id', $first->id)->id, $owner);

        $this->assertSame(2, $section->traineeMemberships()->where('status', 'active')->count());
        $this->assertSame([$second->id], $session->participants()->pluck('user_id')->all());
    }

    public function test_cancellation_requires_reason(): void
    {
        [, $actor] = $this->roleUser(UserParticipationRoleEnum::COACH);
        $section = app(CreateSportsSectionHandler::class)->handle($actor, $this->sectionData());
        $session = app(CreateTrainingSessionHandler::class)->handle($section, $actor, ['starts_at' => now()->addDay(), 'ends_at' => now()->addDay()->addHour()]);

        $this->expectException(SportsSectionException::class);
        app(TransitionTrainingSessionHandler::class)->handle($section, $session, $actor, TrainingSessionStatusEnum::CANCELLED);
    }

    public function test_only_training_event_with_matching_coordinates_can_be_linked_and_is_a_projection(): void
    {
        [, $actor] = $this->roleUser(UserParticipationRoleEnum::COACH);
        $venue = Venue::factory()->create();
        $court = $this->court($venue);
        $section = app(CreateSportsSectionHandler::class)->handle($actor, $this->sectionData(['primary_venue_id' => $venue->id, 'primary_venue_court_id' => $court->id]));
        $startsAt = now()->addDay()->startOfHour();
        $endsAt = $startsAt->copy()->addHour();
        $session = app(CreateTrainingSessionHandler::class)->handle($section, $actor, ['starts_at' => $startsAt, 'ends_at' => $endsAt]);
        $event = Event::factory()->create([
            'organizer_actor_id' => $actor->id, 'venue_id' => $venue->id, 'venue_court_id' => $court->id,
            'type' => EventTypeEnum::TRAINING, 'status' => EventStatusEnum::DRAFT,
            'starts_at' => $startsAt, 'ends_at' => $endsAt,
        ]);
        app(LinkTrainingSessionEventHandler::class)->handle($section, $session, $event, $actor);
        app(TransitionTrainingSessionHandler::class)->handle($section, $session->refresh(), $actor, TrainingSessionStatusEnum::CONFIRMED);

        $this->assertSame($event->id, $session->refresh()->event_id);
        $this->assertSame(EventStatusEnum::PUBLISHED, $event->refresh()->status);

        $other = app(CreateTrainingSessionHandler::class)->handle($section, $actor, ['starts_at' => $startsAt->addDay(), 'ends_at' => $endsAt->addDay()]);
        $wrongType = Event::factory()->create(['organizer_actor_id' => $actor->id, 'venue_id' => $venue->id, 'venue_court_id' => $court->id, 'type' => EventTypeEnum::GAME_TRAINING, 'starts_at' => $other->starts_at, 'ends_at' => $other->ends_at]);
        $this->expectException(SportsSectionException::class);
        app(LinkTrainingSessionEventHandler::class)->handle($section, $other, $wrongType, $actor);
    }

    public function test_contact_source_switches_between_section_and_head_coach_contacts(): void
    {
        [$owner, $actor] = $this->roleUser(UserParticipationRoleEnum::COACH);
        $section = app(CreateSportsSectionHandler::class)->handle($actor, $this->sectionData());
        app(ManageSportsSectionContactHandler::class)->store($section, $owner, ['type' => 'phone', 'value' => '+79990000000']);
        $owner->contacts()->create(['type' => 'telegram', 'value' => '@sectioncoach', 'is_public' => true]);
        $section->update(['status' => 'active', 'contact_source' => 'section']);

        $this->get(route('sports-sections.show', $section))->assertOk()->assertSee('+79990000000');
        $section->update(['contact_source' => 'head_coach']);
        $this->get(route('sports-sections.show', $section))->assertOk()->assertSee('@sectioncoach');
    }

    public function test_section_photo_uses_shared_media_pipeline_and_foreign_user_cannot_upload(): void
    {
        Storage::fake('public');
        [$owner, $actor] = $this->roleUser(UserParticipationRoleEnum::COACH);
        [$outsider] = $this->roleUser(UserParticipationRoleEnum::COACH);
        $section = app(CreateSportsSectionHandler::class)->handle($actor, $this->sectionData());

        $this->actingAs($owner)->post(route('account.sports-sections.photos.store', $section), [
            'photo' => UploadedFile::fake()->image('training.jpg', 1400, 900),
        ])->assertRedirect()->assertSessionHas('status');
        $photo = $section->media()->sole();
        $this->assertSame('sports_section', $photo->mediable_type);
        $this->assertSame('image/webp', $photo->mime);
        $this->assertTrue($photo->is_featured);
        $this->actingAs($owner)->get(route('account.sports-sections.edit', $section))
            ->assertOk()->assertSee('Тренеры')->assertSee($photo->publicUrl(), false);

        $this->actingAs($outsider)->post(route('account.sports-sections.photos.store', $section), [
            'photo' => UploadedFile::fake()->image('foreign.jpg'),
        ])->assertRedirect()->assertSessionHasErrors('section');
        $this->assertSame(1, $section->media()->count());
    }

    public function test_foreign_membership_id_cannot_be_used_to_manage_another_section(): void
    {
        [$firstOwner, $firstActor] = $this->roleUser(UserParticipationRoleEnum::COACH);
        [$secondOwner, $secondActor] = $this->roleUser(UserParticipationRoleEnum::COACH);
        [$player] = $this->roleUser(UserParticipationRoleEnum::PLAYER);
        $first = app(CreateSportsSectionHandler::class)->handle($firstActor, $this->sectionData());
        $second = app(CreateSportsSectionHandler::class)->handle($secondActor, $this->sectionData());
        $membership = app(ManageSectionTraineeHandler::class)->activate($second, $player, $secondOwner);

        $this->expectException(SportsSectionException::class);
        app(ManageSectionTraineeHandler::class)->deactivate($first, $membership, $firstOwner, null);
    }

    /** @return array{User, Actor} */
    private function roleUser(UserParticipationRoleEnum $role): array
    {
        $user = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $user->participationRoles(false)->create([
            'role' => $role, 'status' => UserParticipationRoleStatusEnum::ACTIVE,
            'assigned_at' => now(), 'assigned_by' => $user->id,
            'assigner' => UserParticipationRoleAssignerEnum::USER,
        ]);
        $actor = app(CurrentActorResolver::class)->resolve($user, null);

        return [$user, $actor];
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private function sectionData(array $overrides = []): array
    {
        return array_replace([
            'name' => 'Секция '.fake()->unique()->numberBetween(1, 999999),
            'training_mode' => 'small_group', 'game_format' => 'basketball_5x5',
            'pricing_type' => 'free', 'single_session_price_minor' => null,
            'currency' => 'RUB', 'contact_source' => 'head_coach',
        ], $overrides);
    }

    private function court(Venue $venue): VenueCourt
    {
        return $venue->courts()->first()
            ?? $venue->courts()->create(['name' => 'Зал 1', 'alias' => 'zal-1', 'sort_order' => 10, 'is_primary' => true]);
    }
}
