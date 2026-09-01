<?php

namespace App\Modules\VenueBooking\Application\UseCases;

use App\Modules\Event\Application\Services\StandaloneGameFormationService;
use App\Modules\Event\Application\Services\StandaloneGameInitialSelectionService;
use App\Modules\Event\Domain\Enums\EventParticipantRoleEnum;
use App\Modules\Event\Domain\Enums\EventParticipantStatusEnum;
use App\Modules\Event\Domain\Enums\EventStatusEnum;
use App\Modules\Event\Domain\Enums\EventTypeEnum;
use App\Modules\Event\Domain\Enums\EventVisibilityEnum;
use App\Modules\Event\Domain\Enums\GameFormatEnum;
use App\Modules\Event\Domain\Enums\GameRecruitmentModeEnum;
use App\Modules\Event\Domain\Enums\GameScoringTypeEnum;
use App\Modules\Event\Domain\Enums\GameTimingModeEnum;
use App\Modules\Event\Domain\Enums\VenueBookingStatusEnum;
use App\Modules\Event\Domain\Events\EventChanged;
use App\Modules\Event\Domain\Events\EventCreatedFromBooking;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\VenueBooking\Domain\Exceptions\VenueBookingTransitionException;
use App\Modules\VenueBooking\Domain\Models\VenueBooking;
use App\Support\Features\FeatureFlags;
use App\Support\Features\VenueRentalFeature;
use App\Support\Text\CyrillicTransliterator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class CreateEventFromConfirmedVenueBookingHandler
{
    public function __construct(
        private FeatureFlags $features,
        private CyrillicTransliterator $transliterator,
        private StandaloneGameFormationService $standaloneGames,
        private StandaloneGameInitialSelectionService $initialSelections,
    ) {}

    /** @param array<string, mixed> $data */
    public function handle(int $bookingId, Actor $actor, array $data): Event
    {
        $this->features->ensureEnabled(VenueRentalFeature::BOOKING_EVENTS);
        $reference = VenueBooking::query()->findOrFail($bookingId);

        return DB::transaction(function () use ($bookingId, $reference, $actor, $data): Event {
            Venue::query()->lockForUpdate()->findOrFail($reference->venue_id);
            $booking = VenueBooking::query()->lockForUpdate()->findOrFail($bookingId);
            $isRequester = $actor->user_id !== null && $actor->user_id === $booking->requester_user_id;
            $isSuperadmin = $actor->user?->hasSystemRole(UserSystemRoleEnum::SUPERADMIN) ?? false;
            if (! $isRequester && ! $isSuperadmin) {
                throw new VenueBookingTransitionException('Недостаточно прав для создания мероприятия.', 'BOOKING_FORBIDDEN');
            }
            if ($isSuperadmin && trim((string) ($data['emergency_reason'] ?? '')) === '') {
                throw new VenueBookingTransitionException('Для аварийного действия superadmin должна быть указана причина.', 'EMERGENCY_REASON_REQUIRED');
            }
            $existing = Event::query()->where('booking_id', $booking->id)->lockForUpdate()->first();
            if ($existing !== null) {
                return $existing;
            }
            if ($booking->status !== VenueBookingStatusEnum::CONFIRMED || $booking->flow !== 'rental') {
                throw new VenueBookingTransitionException('Мероприятие можно создать только из подтверждённой брони.', 'BOOKING_NOT_CONFIRMED');
            }

            $event = Event::query()->create([
                'venue_id' => $booking->venue_id,
                'booking_id' => $booking->id,
                'booking_snapshot' => [
                    'booking_public_id' => $booking->public_id,
                    'scope' => $booking->scope?->value,
                    'starts_at' => $booking->starts_at->toIso8601String(),
                    'ends_at' => $booking->ends_at->toIso8601String(),
                    'pricing' => data_get($booking->quote_snapshot, 'pricing'),
                    'policy_version' => data_get($booking->quote_snapshot, 'policy.version'),
                    'emergency_reason' => $isSuperadmin ? trim((string) $data['emergency_reason']) : null,
                ],
                'organizer_actor_id' => $booking->created_by_actor_id,
                'title' => trim((string) $data['title']),
                'alias' => Str::slug($this->transliterator->transliterate((string) $data['title'])),
                'type' => EventTypeEnum::from($data['type'] ?? EventTypeEnum::TRAINING->value),
                'status' => EventStatusEnum::PUBLISHED,
                'visibility' => EventVisibilityEnum::from($data['visibility'] ?? EventVisibilityEnum::PUBLIC->value),
                'description' => $data['description'] ?? null,
                'starts_at' => $booking->starts_at,
                'ends_at' => $booking->ends_at,
                'max_participants' => $data['max_participants'] ?? null,
            ]);
            if ($booking->requester_user_id !== null) {
                $event->participants()->create([
                    'user_id' => $booking->requester_user_id, 'role' => EventParticipantRoleEnum::ORGANIZER,
                    'status' => EventParticipantStatusEnum::CONFIRMED, 'joined_at' => now(),
                ]);
            }
            if ($event->type === EventTypeEnum::GAME) {
                $game = $this->standaloneGames->initialize(
                    $event, $actor, null, null, 5, 5,
                    GameScoringTypeEnum::STREETBALL,
                    GameFormatEnum::STREETBALL_3X3,
                    GameTimingModeEnum::WHOLE_GAME,
                    null,
                    GameRecruitmentModeEnum::PREFORMED_TEAMS,
                    true,
                );
                $this->initialSelections->apply($game, $actor, null, null);
            }
            $booking->forceFill(['event_id' => $event->id])->save();
            DB::afterCommit(static function () use ($event, $booking): void {
                event(new EventCreatedFromBooking($event->id, $booking->id));
                event(new EventChanged($event->id));
            });

            return $event->load(['sourceBooking', 'participants']);
        });
    }
}
