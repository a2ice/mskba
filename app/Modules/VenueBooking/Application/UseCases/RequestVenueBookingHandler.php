<?php

namespace App\Modules\VenueBooking\Application\UseCases;

use App\Modules\Event\Domain\Enums\VenueBookingStatusEnum;
use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\VenueBooking\Application\Services\IdempotentVenueBookingCommand;
use App\Modules\VenueBooking\Application\Services\VenueBookingOutbox;
use App\Modules\VenueBooking\Domain\Enums\VenueBookingPartyRole;
use App\Modules\VenueBooking\Domain\Enums\VenueBookingPaymentState;
use App\Modules\VenueBooking\Domain\Events\VenueBookingRequested;
use App\Modules\VenueBooking\Domain\Exceptions\VenueBookingTransitionException;
use App\Modules\VenueBooking\Domain\Models\VenueBooking;
use App\Modules\VenueBooking\Domain\Models\VenueBookingQuote;
use App\Modules\VenueBooking\Domain\Services\VenueBookingLifecycle;
use App\Support\Features\FeatureFlags;
use App\Support\Features\VenueRentalFeature;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class RequestVenueBookingHandler
{
    public function __construct(
        private VenueBookingLifecycle $lifecycle,
        private FeatureFlags $features,
        private IdempotentVenueBookingCommand $commands,
        private VenueBookingOutbox $outbox,
    ) {}

    public function handle(
        Actor $actor,
        string $quotePublicId,
        ?string $idempotencyKey = null,
        ?string $correlationId = null,
    ): VenueBooking {
        $this->features->ensureEnabled(VenueRentalFeature::RENTAL_FLOW);
        $user = $actor->user?->canonical();

        if ($user === null || ! $user->isConfirmed()) {
            throw new VenueBookingTransitionException('Для заявки нужен подтверждённый аккаунт.', 'BOOKING_FORBIDDEN');
        }

        $quote = VenueBookingQuote::query()->where('public_id', $quotePublicId)->firstOrFail();

        $booking = $this->commands->execute(
            'venue_booking.request',
            $actor,
            ['quote_id' => $quotePublicId],
            function () use ($actor, $user, $quote): VenueBooking {
                return DB::transaction(function () use ($actor, $user, $quote): VenueBooking {
                    Venue::query()->lockForUpdate()->findOrFail($quote->venue_id);
                    $quote = VenueBookingQuote::query()->lockForUpdate()->findOrFail($quote->id);
                    $existing = VenueBooking::query()->where('quote_id', $quote->id)->first();

                    if ($existing !== null) {
                        if ($existing->requester_user_id !== $user->id) {
                            throw new VenueBookingTransitionException('Этот расчёт уже использован.', 'QUOTE_ALREADY_USED');
                        }

                        return $existing;
                    }

                    $now = CarbonImmutable::now();
                    if (! $now->lessThan($quote->valid_until)) {
                        throw new VenueBookingTransitionException('Срок действия расчёта истёк.', 'QUOTE_EXPIRED');
                    }

                    if ($quote->quoted_for_user_id !== null && $quote->quoted_for_user_id !== $user->id) {
                        throw new VenueBookingTransitionException('Расчёт принадлежит другому пользователю.', 'QUOTE_FORBIDDEN');
                    }

                    $requiresPayment = (bool) data_get($quote->snapshot, 'policy.requires_payment', false);
                    $booking = VenueBooking::query()->create([
                        'public_id' => (string) Str::uuid(),
                        'flow' => 'rental',
                        'venue_id' => $quote->venue_id,
                        'event_id' => null,
                        'created_by_actor_id' => $actor->id,
                        'requester_user_id' => $user->id,
                        'policy_version_id' => $quote->policy_version_id,
                        'quote_id' => $quote->id,
                        'quote_snapshot' => $quote->snapshot,
                        'payment_state' => $requiresPayment
                            ? VenueBookingPaymentState::NOT_STARTED
                            : VenueBookingPaymentState::NOT_REQUIRED,
                        'status' => VenueBookingStatusEnum::REQUESTED,
                        'scope' => $quote->scope,
                        'starts_at' => $quote->starts_at,
                        'ends_at' => $quote->ends_at,
                        'optimistic_version' => 1,
                        'requested_at' => $now,
                    ]);
                    $booking->parties()->create([
                        'user_id' => $user->id,
                        'role' => VenueBookingPartyRole::APPLICANT,
                    ]);
                    $this->lifecycle->recordRequested($booking, $actor);

                    $this->outbox->record($booking->id, VenueBookingRequested::class);

                    return $booking;
                });
            },
            $idempotencyKey,
            $correlationId,
        );

        return $booking->fresh(['transitions', 'parties']);
    }
}
