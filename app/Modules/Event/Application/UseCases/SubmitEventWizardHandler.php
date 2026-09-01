<?php

namespace App\Modules\Event\Application\UseCases;

use App\Modules\Event\Application\DTO\EventSubmissionResult;
use App\Modules\Event\Application\Services\VenueEventAvailability;
use App\Modules\Event\Domain\Enums\EventTypeEnum;
use App\Modules\Event\Domain\Enums\VenueBookingScopeEnum;
use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\Telegram\Application\UseCases\PrepareTelegramEventPublicationsHandler;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\VenueBooking\Application\UseCases\RequestEventVenueBookingHandler;
use App\Modules\VenueBooking\Domain\Models\VenueBookingPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final readonly class SubmitEventWizardHandler
{
    public function __construct(
        private CreateEventHandler $events,
        private CreateStandaloneGameHandler $standaloneGames,
        private RequestEventVenueBookingHandler $rentalBookings,
        private PrepareTelegramEventPublicationsHandler $telegramPublications,
        private VenueEventAvailability $availability,
    ) {}

    /** @param array<string, mixed> $data */
    public function handle(Actor $actor, array $data): EventSubmissionResult
    {
        $venue = Venue::query()->findOrFail((int) $data['venue_id']);
        $rentalPolicyExists = VenueBookingPolicy::query()
            ->where('venue_id', $venue->id)
            ->where('active_marker', true)
            ->where('is_enabled', true)
            ->exists();

        if ($rentalPolicyExists) {
            return EventSubmissionResult::booking(
                $this->rentalBookings->handle($actor, $venue, $data),
            );
        }

        if (! $venue->hasFreeAccess()) {
            $timezone = $venue->schedule()->value('timezone')
                ?: config('app.timezone', 'Europe/Moscow');
            $startsAt = CarbonImmutable::parse((string) $data['starts_at'], $timezone);
            $this->availability->assertAvailable(
                $venue,
                $startsAt,
                $startsAt->addMinutes((int) $data['duration_minutes']),
                scope: VenueBookingScopeEnum::from(
                    $data['booking_scope'] ?? VenueBookingScopeEnum::WHOLE->value,
                ),
            );
            throw new InvalidArgumentException(
                'Для этой площадки ещё не опубликованы условия аренды. Обратитесь к владельцу площадки.',
            );
        }

        $event = DB::transaction(function () use ($actor, $data) {
            $event = $data['type'] === EventTypeEnum::GAME->value
                ? $this->standaloneGames->handle($actor, $data)
                : $this->events->handle($actor, $data);

            if ((bool) ($data['publish_to_telegram'] ?? false)) {
                $this->telegramPublications->handle($event, $data['telegram_chat_ids'] ?? []);
            }

            return $event;
        });

        return EventSubmissionResult::event($event);
    }
}
