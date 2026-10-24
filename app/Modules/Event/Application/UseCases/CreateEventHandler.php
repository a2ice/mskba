<?php

namespace App\Modules\Event\Application\UseCases;

use App\Modules\Event\Application\Services\StandaloneGameFormationService;
use App\Modules\Event\Application\Services\StandaloneGameInitialSelectionService;
use App\Modules\Event\Application\Services\VenueEventAvailability;
use App\Modules\Event\Domain\Enums\EventParticipantRoleEnum;
use App\Modules\Event\Domain\Enums\EventParticipantStatusEnum;
use App\Modules\Event\Domain\Enums\EventStatusEnum;
use App\Modules\Event\Domain\Enums\EventTypeEnum;
use App\Modules\Event\Domain\Enums\EventVisibilityEnum;
use App\Modules\Event\Domain\Enums\GameFormatEnum;
use App\Modules\Event\Domain\Enums\GameRecruitmentModeEnum;
use App\Modules\Event\Domain\Enums\GameScoringTypeEnum;
use App\Modules\Event\Domain\Enums\GameTimingModeEnum;
use App\Modules\Event\Domain\Enums\VenueBookingScopeEnum;
use App\Modules\Event\Domain\Enums\VenueBookingStatusEnum;
use App\Modules\Event\Domain\Events\EventChanged;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleEnum;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\Venue\Domain\Models\VenueCourt;
use App\Support\Text\CyrillicTransliterator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class CreateEventHandler
{
    public function __construct(
        private readonly VenueEventAvailability $availability,
        private readonly CyrillicTransliterator $transliterator,
        private readonly StandaloneGameFormationService $standaloneGames,
        private readonly StandaloneGameInitialSelectionService $initialSelections,
    ) {}

    /** @param array<string, mixed> $data */
    public function handle(Actor $actor, array $data): Event
    {
        if ($actor->user_id === null) {
            throw new InvalidArgumentException('Войдите в аккаунт, чтобы создать мероприятие.');
        }

        // Other creation workflows keep their legacy semantics unless they opt in
        // to an explicit attendance decision. Filament always supplies this flag.
        $explicitChoice = array_key_exists('organizer_participates', $data);
        $organizerParticipates = $explicitChoice
            ? filter_var($data['organizer_participates'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
            : true;
        if ($organizerParticipates === null) {
            throw new InvalidArgumentException('Неверно указано участие организатора.');
        }

        $roles = $data['participation_roles'] ?? null;
        if ($explicitChoice && $organizerParticipates && (! is_array($roles) || $roles === [])) {
            throw new InvalidArgumentException('Выберите хотя бы одну роль участия.');
        }
        if ($roles !== null && (! is_array($roles)
            || collect($roles)->contains(fn ($role): bool => ! is_string($role)
                || UserParticipationRoleEnum::tryFrom($role) === null
                || $role === UserParticipationRoleEnum::ORGANIZER->value))) {
            throw new InvalidArgumentException('Выбрана недопустимая роль участия.');
        }
        $roles = $organizerParticipates
            ? array_values(array_unique($roles ?? [UserParticipationRoleEnum::PLAYER->value]))
            : [];

        $event = DB::transaction(function () use ($actor, $data, $organizerParticipates, $roles): Event {
            // Единый порядок блокировок для бронирований: сначала venue, затем bookings/event.
            $venue = Venue::query()->lockForUpdate()->findOrFail($data['venue_id']);
            // Some verified venues (including the production -> dev fixture)
            // predate VenueCourt and have no court rows. NULL court_id means
            // the entire venue, as supported by the existing schema/availability.
            $court = isset($data['venue_court_id'])
                ? VenueCourt::query()
                    ->where('venue_id', $venue->id)
                    ->whereKey((int) $data['venue_court_id'])
                    ->first()
                : ($venue->primaryCourt()->first() ?? $venue->courts()->first());
            if (isset($data['venue_court_id']) && $court === null) {
                throw new InvalidArgumentException('Выбранная игровая зона отсутствует на площадке.');
            }
            $timezone = $venue->schedule()->value('timezone') ?: config('app.timezone', 'Europe/Moscow');
            $localStart = CarbonImmutable::parse($data['starts_at'], $timezone);
            $durationMinutes = (int) $data['duration_minutes'];

            if ($durationMinutes < 1 || $durationMinutes > 1440) {
                throw new InvalidArgumentException('Мероприятие должно завершиться в течение суток.');
            }

            // PostgreSQL connection uses the application timezone. Keep the
            // local value here: Laravel serializes bindings without an offset,
            // and converting to UTC first would shift the event by three hours.
            $startsAt = $localStart;
            $endsAt = $localStart->addMinutes($durationMinutes);

            $bookingScope = VenueBookingScopeEnum::from($data['booking_scope'] ?? VenueBookingScopeEnum::WHOLE->value);
            $this->availability->assertAvailable($venue, $startsAt, $endsAt, scope: $bookingScope, court: $court);

            $bookingStatus = $venue->hasFreeAccess()
                ? VenueBookingStatusEnum::CONFIRMED
                : VenueBookingStatusEnum::PENDING;

            $event = Event::query()->create([
                'venue_id' => $venue->id,
                'venue_court_id' => $court?->id,
                'organizer_actor_id' => $actor->id,
                'title' => $data['title'],
                'alias' => Str::slug($this->transliterator->transliterate($data['title'])),
                'type' => EventTypeEnum::from($data['type']),
                'status' => $bookingStatus === VenueBookingStatusEnum::CONFIRMED
                    ? EventStatusEnum::PUBLISHED
                    : EventStatusEnum::DRAFT,
                'visibility' => EventVisibilityEnum::from($data['visibility']),
                'description' => $data['description'] ?? null,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'max_participants' => $data['max_participants'] ?? null,
            ]);

            $event->booking()->create([
                'venue_id' => $venue->id,
                'venue_court_id' => $court?->id,
                'created_by_actor_id' => $actor->id,
                'status' => $bookingStatus,
                'scope' => $bookingScope,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
            ]);

            $event->participants()->create([
                'user_id' => $actor->user_id,
                'role' => EventParticipantRoleEnum::ORGANIZER,
                'participation_roles' => $roles,
                'status' => $organizerParticipates
                    ? EventParticipantStatusEnum::CONFIRMED
                    : EventParticipantStatusEnum::LEFT,
                'joined_at' => $organizerParticipates ? now() : null,
                'confirmation_version' => $event->participation_confirmation_version,
            ]);

            if ($event->type === EventTypeEnum::GAME) {
                $game = $this->standaloneGames->initialize(
                    $event,
                    $actor,
                    null,
                    null,
                    (int) ($data['side_a_size'] ?? 5),
                    (int) ($data['side_b_size'] ?? 5),
                    GameScoringTypeEnum::from($data['scoring_type'] ?? GameScoringTypeEnum::STREETBALL->value),
                    GameFormatEnum::from($data['game_format'] ?? GameFormatEnum::STREETBALL_3X3->value),
                    GameTimingModeEnum::from($data['timing_mode'] ?? GameTimingModeEnum::WHOLE_GAME->value),
                    isset($data['periods_count']) ? (int) $data['periods_count'] : null,
                    GameRecruitmentModeEnum::from(
                        $data['game_recruitment_mode'] ?? GameRecruitmentModeEnum::PREFORMED_TEAMS->value,
                    ),
                    (bool) ($data['game_accepts_applications'] ?? true),
                );

                $this->initialSelections->apply(
                    $game,
                    $actor,
                    isset($data['team_a_id']) ? (int) $data['team_a_id'] : null,
                    isset($data['team_b_id']) ? (int) $data['team_b_id'] : null,
                );
            }

            return $event->load(['venue', 'court', 'booking', 'participants.user']);
        });

        event(new EventChanged($event->id));

        return $event;
    }
}
