<?php

namespace Tests\Feature\SportsSection;

use App\Modules\Event\Domain\Enums\EventStatusEnum;
use App\Modules\Event\Domain\Enums\EventTypeEnum;
use App\Modules\Event\Domain\Enums\EventVisibilityEnum;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleAssignerEnum;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleEnum;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleStatusEnum;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\SportsSection\Application\UseCases\CreateSportsSectionHandler;
use App\Modules\SportsSection\Application\UseCases\CreateTrainingSessionHandler;
use App\Modules\SportsSection\Application\UseCases\PublishTrainingSessionEventHandler;
use App\Modules\SportsSection\Domain\Models\SportsSection;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\Venue\Domain\Models\VenueCourt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SportsSectionProductFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('features.sports_sections.enabled', true);
    }

    public function test_session_is_published_as_event_projection_without_manual_event_or_booking(): void
    {
        [$owner, $actor] = $this->roleUser(UserParticipationRoleEnum::COACH);
        $venue = Venue::factory()->create();
        $court = $this->court($venue);
        $section = app(CreateSportsSectionHandler::class)->handle($actor, $this->sectionData([
            'primary_venue_id' => $venue->id,
            'primary_venue_court_id' => $court->id,
        ]));
        $session = app(CreateTrainingSessionHandler::class)->handle($section, $actor, [
            'starts_at' => now()->addDay()->startOfHour(),
            'ends_at' => now()->addDay()->startOfHour()->addHour(),
        ]);

        $session = app(PublishTrainingSessionEventHandler::class)->handle($section, $session, $actor);
        $event = $session->event;

        $this->assertNotNull($event);
        $this->assertSame(EventTypeEnum::TRAINING, $event->type);
        $this->assertSame(EventVisibilityEnum::PUBLIC, $event->visibility);
        $this->assertSame(EventStatusEnum::DRAFT, $event->status);
        $this->assertSame($session->starts_at->timestamp, $event->starts_at->timestamp);
        $this->assertSame($session->ends_at->timestamp, $event->ends_at->timestamp);
        $this->assertNull($event->booking_id);
        $this->assertFalse($event->booking()->exists());

        $this->actingAs($owner)
            ->get(route('account.sports-sections.edit', $section))
            ->assertOk()
            ->assertDontSee('ID Event')
            ->assertSee('Открыть публичное мероприятие');
    }

    public function test_account_ui_offers_publish_action_instead_of_event_id_input(): void
    {
        [$owner, $actor] = $this->roleUser(UserParticipationRoleEnum::COACH);
        $venue = Venue::factory()->create();
        $court = $this->court($venue);
        $section = app(CreateSportsSectionHandler::class)->handle($actor, $this->sectionData([
            'primary_venue_id' => $venue->id,
            'primary_venue_court_id' => $court->id,
        ]));
        app(CreateTrainingSessionHandler::class)->handle($section, $actor, [
            'starts_at' => now()->addDay()->startOfHour(),
            'ends_at' => now()->addDay()->startOfHour()->addHour(),
        ]);

        $this->actingAs($owner)
            ->get(route('account.sports-sections.edit', $section))
            ->assertOk()
            ->assertDontSee('ID Event')
            ->assertSee('Опубликовать на MSKBA')
            ->assertSee('Состав занятия');
    }

    public function test_venue_override_does_not_inherit_court_from_another_venue(): void
    {
        [, $actor] = $this->roleUser(UserParticipationRoleEnum::COACH);
        $primaryVenue = Venue::factory()->create();
        $primaryCourt = $this->court($primaryVenue);
        $otherVenue = Venue::factory()->create();
        $this->court($otherVenue);
        $section = app(CreateSportsSectionHandler::class)->handle($actor, $this->sectionData([
            'primary_venue_id' => $primaryVenue->id,
            'primary_venue_court_id' => $primaryCourt->id,
        ]));

        $session = app(CreateTrainingSessionHandler::class)->handle($section, $actor, [
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addHour(),
            'venue_id' => $otherVenue->id,
        ]);

        $this->assertSame($otherVenue->id, $session->venue_id);
        $this->assertNull($session->venue_court_id);
    }

    public function test_public_catalog_can_filter_active_sections(): void
    {
        [, $actor] = $this->roleUser(UserParticipationRoleEnum::COACH);
        $alpha = app(CreateSportsSectionHandler::class)->handle($actor, $this->sectionData([
            'name' => 'Север Баскет',
            'pricing_type' => 'free',
        ]));
        $alpha->update(['status' => 'active']);

        $beta = app(CreateSportsSectionHandler::class)->handle($actor, $this->sectionData([
            'name' => 'Юг Академия',
            'pricing_type' => 'paid',
            'single_session_price_minor' => 150000,
        ]));
        $beta->update(['status' => 'active']);

        $this->get(route('sports-sections.index', ['q' => 'Север', 'pricing_type' => 'free']))
            ->assertOk()
            ->assertSee('Север Баскет')
            ->assertDontSee('Юг Академия');
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
        $actor = app(CurrentActorResolver::class)->resolve($user, null);

        return [$user, $actor];
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private function sectionData(array $overrides = []): array
    {
        return array_replace([
            'name' => 'Секция '.fake()->unique()->numberBetween(1, 999999),
            'description' => 'Регулярные тренировки по баскетболу.',
            'training_mode' => 'small_group',
            'game_format' => 'basketball_5x5',
            'pricing_type' => 'free',
            'single_session_price_minor' => null,
            'currency' => 'RUB',
            'contact_source' => 'head_coach',
        ], $overrides);
    }

    private function court(Venue $venue): VenueCourt
    {
        return $venue->courts()->first()
            ?? $venue->courts()->create(['name' => 'Зал 1', 'alias' => 'zal-1', 'sort_order' => 10, 'is_primary' => true]);
    }
}
