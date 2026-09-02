<?php

namespace App\Modules\VenueBooking\Application\UseCases;

use App\Modules\Event\Domain\Enums\VenueBookingScopeEnum;
use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\VenueBooking\Domain\Exceptions\VenueBookingTransitionException;
use App\Modules\VenueBooking\Domain\Models\VenueBooking;
use App\Modules\VenueBooking\Domain\Models\VenueBookingEventIntent;
use App\Support\Features\FeatureDisabledException;
use App\Support\Features\FeatureFlags;
use App\Support\Features\VenueRentalFeature;
use App\Support\Features\VenueRentalRollout;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

final readonly class RequestEventVenueBookingHandler
{
    public function __construct(
        private QuoteVenueBookingHandler $quotes,
        private RequestVenueBookingHandler $bookings,
        private FeatureFlags $features,
        private VenueRentalRollout $rollout,
    ) {}

    /** @param array<string, mixed> $data */
    public function handle(Actor $actor, Venue $venue, array $data): VenueBooking
    {
        $this->features->ensureEnabled(VenueRentalFeature::BOOKING_EVENTS);
        $this->ensureRolloutAllowsBooking($actor, $venue);

        return DB::transaction(function () use ($actor, $venue, $data): VenueBooking {
            $lockedVenue = Venue::query()->lockForUpdate()->findOrFail($venue->id);
            $requestKey = (string) $data['event_request_id'];
            $existingIntent = VenueBookingEventIntent::query()
                ->with('creatorActor')
                ->where('request_key', $requestKey)
                ->first();
            if ($existingIntent !== null) {
                if ($existingIntent->creatorActor->user_id === null
                    || $existingIntent->creatorActor->user_id !== $actor->user_id) {
                    throw new VenueBookingTransitionException(
                        'Идентификатор заявки уже использован.',
                        'EVENT_REQUEST_KEY_FORBIDDEN',
                    );
                }

                return VenueBooking::query()->findOrFail($existingIntent->venue_booking_id);
            }
            $timezone = $lockedVenue->schedule()->value('timezone')
                ?: config('app.timezone', 'Europe/Moscow');
            $quote = $this->quotes->handle(
                $lockedVenue,
                CarbonImmutable::parse((string) $data['starts_at'], $timezone),
                (int) $data['duration_minutes'],
                VenueBookingScopeEnum::from($data['booking_scope'] ?? VenueBookingScopeEnum::WHOLE->value),
                $actor->user,
            );
            $booking = $this->bookings->handle(
                $actor,
                $quote->publicId,
                (string) $data['event_request_id'],
                (string) $data['event_request_id'],
            );
            $eventPayload = Arr::only($data, [
                'title',
                'type',
                'visibility',
                'description',
                'max_participants',
                'game_recruitment_mode',
                'game_accepts_applications',
                'team_a_id',
                'team_b_id',
                'side_a_size',
                'side_b_size',
                'scoring_type',
                'game_format',
                'timing_mode',
                'periods_count',
            ]);
            $telegramChatIds = (bool) ($data['publish_to_telegram'] ?? false)
                ? array_values(array_unique(array_map('intval', $data['telegram_chat_ids'] ?? [])))
                : null;

            VenueBookingEventIntent::query()->firstOrCreate(
                ['venue_booking_id' => $booking->id],
                [
                    'created_by_actor_id' => $actor->id,
                    'request_key' => $requestKey,
                    'event_payload' => $eventPayload,
                    'telegram_chat_ids' => $telegramChatIds,
                ],
            );

            return $booking->load('eventIntent');
        });
    }

    private function ensureRolloutAllowsBooking(Actor $actor, Venue $venue): void
    {
        $user = $actor->user?->canonical();
        $stableKey = (string) ($user?->id ?? $actor->id);

        foreach ([VenueRentalFeature::RENTAL_FLOW, VenueRentalFeature::BOOKING_EVENTS] as $feature) {
            if (! $this->rollout->allows($feature, $user, $venue->id, null, $stableKey, true)) {
                throw new FeatureDisabledException($feature);
            }
        }
    }
}
