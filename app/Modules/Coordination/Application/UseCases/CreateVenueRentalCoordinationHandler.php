<?php

namespace App\Modules\Coordination\Application\UseCases;

use App\Modules\Coordination\Domain\Enums\VenueRentalCoordinationStatus;
use App\Modules\Coordination\Domain\Events\VenueRentalCoordinationCreated;
use App\Modules\Coordination\Domain\Models\VenueRentalCoordination;
use App\Modules\Event\Domain\Enums\VenueBookingScopeEnum;
use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\VenueBooking\Application\UseCases\QuoteVenueBookingHandler;
use App\Support\Features\FeatureFlags;
use App\Support\Features\VenueRentalFeature;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class CreateVenueRentalCoordinationHandler
{
    public function __construct(private FeatureFlags $features, private QuoteVenueBookingHandler $quotes) {}

    /** @param array<string, mixed> $data */
    public function handle(Actor $actor, array $data): VenueRentalCoordination
    {
        $this->features->ensureEnabled(VenueRentalFeature::COORDINATION);
        $user = $actor->user?->canonical();
        if ($user === null || ! $user->isConfirmed()) {
            throw new InvalidArgumentException('Для сбора участников нужен подтверждённый аккаунт.');
        }

        $venue = Venue::query()->findOrFail((int) $data['venue_id']);
        $scope = VenueBookingScopeEnum::from((string) $data['scope']);
        $timezone = $venue->schedule()->value('timezone') ?: config('app.timezone', 'UTC');
        $quote = $this->quotes->handle(
            $venue,
            CarbonImmutable::parse((string) $data['starts_at'], $timezone),
            (int) $data['duration_minutes'],
            $scope,
            $user,
        );
        $databaseTimezone = (string) config('app.timezone', 'UTC');

        return DB::transaction(function () use ($actor, $user, $venue, $scope, $quote, $data, $databaseTimezone): VenueRentalCoordination {
            $coordination = VenueRentalCoordination::query()->create([
                'public_id' => (string) Str::uuid(),
                'organizer_actor_id' => $actor->id,
                'organizer_user_id' => $user->id,
                'venue_id' => $venue->id,
                'title' => trim((string) $data['title']),
                'description' => $data['description'] ?? null,
                'status' => VenueRentalCoordinationStatus::OPEN,
                'visibility' => $data['visibility'] ?? 'public',
                'participants_visibility' => $data['participants_visibility'] ?? 'participants',
                'scope' => $scope,
                'starts_at' => $quote->startsAt->setTimezone($databaseTimezone),
                'ends_at' => $quote->endsAt->setTimezone($databaseTimezone),
            ]);
            $coordination->participants()->create([
                'user_id' => $user->id,
                'joined_at' => now(),
            ]);
            DB::afterCommit(static fn () => event(new VenueRentalCoordinationCreated($coordination->id)));

            return $coordination->load('participants');
        });
    }
}
