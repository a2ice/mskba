<?php

namespace App\Modules\VenueBooking\Application\UseCases;

use App\Modules\Event\Application\Services\VenueEventAvailability;
use App\Modules\Event\Domain\Enums\VenueBookingScopeEnum;
use App\Modules\Event\Domain\Enums\VenueBookingStatusEnum;
use App\Modules\Identity\Application\Services\VerifiedContactOperationalPermissionGranter;
use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\Venue\Application\Services\VenueUserRestrictionService;
use App\Modules\Venue\Domain\Enums\VenueUserRestrictionTypeEnum;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\Venue\Domain\Models\VenueCourt;
use App\Modules\VenueBooking\Application\Services\IdempotentVenueBookingCommand;
use App\Modules\VenueBooking\Application\Services\VenueBookingOutbox;
use App\Modules\VenueBooking\Application\Services\VenuePersonalPricing;
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
use InvalidArgumentException;

final readonly class RequestVenueBookingHandler
{
    public function __construct(
        private VenueBookingLifecycle $lifecycle,
        private FeatureFlags $features,
        private IdempotentVenueBookingCommand $commands,
        private VenueBookingOutbox $outbox,
        private VenueUserRestrictionService $restrictions,
        private VenueEventAvailability $availability,
        private VenuePersonalPricing $personalPricing,
    ) {}

    public function handle(
        Actor $actor,
        string $quotePublicId,
        ?string $idempotencyKey = null,
        ?string $correlationId = null,
        ?\App\Modules\VenueBooking\Domain\Models\VenueRentalPreRequest $preRequest = null,
        ?Actor $representative = null,
    ): VenueBooking {
        $this->features->ensureEnabled(VenueRentalFeature::RENTAL_FLOW);
        abort_unless(($preRequest === null) === ($representative === null), 409);
        $user = $actor->user?->canonical();

        if ($user === null || $user->isBlocked() || $user->trashed()
            || ! app(VerifiedContactOperationalPermissionGranter::class)->hasVerifiedContact($user)) {
            throw new VenueBookingTransitionException('Для бронирования необходимо иметь подтверждённый контакт', 'BOOKING_FORBIDDEN');
        }

        $quote = VenueBookingQuote::query()->where('public_id', $quotePublicId)->firstOrFail();

        $booking = $this->commands->execute(
            'venue_booking.request',
            $actor,
            $preRequest === null ? ['quote_id' => $quotePublicId]
                : ['quote_id' => $quotePublicId, 'pre_request_id' => $preRequest->id, 'representative_id' => $representative?->id],
            function () use ($actor, $user, $quote, $preRequest, $representative): VenueBooking {
                return DB::transaction(function () use ($actor, $user, $quote, $preRequest, $representative): VenueBooking {
                    $venue = Venue::query()->lockForUpdate()->findOrFail($quote->venue_id);
                    $quote = VenueBookingQuote::query()->lockForUpdate()->findOrFail($quote->id);
                    if ($preRequest !== null) {
                        $preRequest = $preRequest->newQuery()->lockForUpdate()->findOrFail($preRequest->id);
                        abort_unless($representative !== null && $preRequest->requester_user_id === $user->id
                            && $preRequest->venue_id === $quote->venue_id && $preRequest->venue_court_id === $quote->venue_court_id
                            && $preRequest->status === \App\Modules\VenueBooking\Domain\Enums\VenueRentalPreRequestStatus::ACTIVE
                            && $preRequest->scope === $quote->scope && $preRequest->starts_at->equalTo($quote->starts_at)
                            && $preRequest->ends_at->equalTo($quote->ends_at), 409);
                        app(\App\Modules\VenueBooking\Application\Services\VenueRentalPreRequests::class)->assertCan($representative, $preRequest, 'offer');
                        abort_if($preRequest->bookings()->where('quote_id', '!=', $quote->id)
                            ->whereIn('status', [VenueBookingStatusEnum::REQUESTED->value, VenueBookingStatusEnum::HELD->value, VenueBookingStatusEnum::CONFIRMED->value])->exists(), 409, 'По предзаявке уже есть предложение.');
                    }
                    $existing = VenueBooking::query()->where('quote_id', $quote->id)->first();

                    if ($existing !== null) {
                        if ($existing->requester_user_id !== $user->id) {
                            throw new VenueBookingTransitionException('Этот расчёт уже использован.', 'QUOTE_ALREADY_USED');
                        }

                        return $existing;
                    }

                    $restriction = $this->restrictions->active(
                        $venue,
                        $user,
                        VenueUserRestrictionTypeEnum::RENTAL_REQUEST,
                    );
                    if ($restriction !== null) {
                        throw new VenueBookingTransitionException(
                            'Для вашего аккаунта заблокирована подача заявок на аренду этой площадки. Причина: '.$restriction->reason,
                            'BOOKING_FORBIDDEN',
                        );
                    }

                    $now = CarbonImmutable::now();
                    if (! $now->lessThan($quote->valid_until)) {
                        throw new VenueBookingTransitionException('Срок действия расчёта истёк.', 'QUOTE_EXPIRED');
                    }

                    if ($quote->quoted_for_user_id !== null && $quote->quoted_for_user_id !== $user->id) {
                        throw new VenueBookingTransitionException('Расчёт принадлежит другому пользователю.', 'QUOTE_FORBIDDEN');
                    }

                    $court = VenueCourt::query()
                        ->where('venue_id', $venue->id)
                        ->when(
                            $quote->venue_court_id !== null,
                            fn ($query) => $query->whereKey($quote->venue_court_id),
                            fn ($query) => $query->orderByDesc('is_primary')->orderBy('sort_order')->orderBy('id'),
                        )
                        ->lockForUpdate()
                        ->first();
                    if ($court === null || ! $court->allowsScope($quote->scope === VenueBookingScopeEnum::WHOLE)) {
                        throw new VenueBookingTransitionException(
                            'Выбранный вариант аренды этого зала больше недоступен.',
                            'BOOKING_SCOPE_UNAVAILABLE',
                        );
                    }

                    // A quote is a price offer, not a time hold. Another
                    // booking may have occupied the slot while the requester
                    // was reviewing the price. Recheck inside the venue lock,
                    // before persisting any requested booking or outbox event.
                    try {
                        $this->availability->assertAvailable(
                            $venue,
                            CarbonImmutable::instance($quote->starts_at)->setTimezone((string) config('app.timezone', 'UTC')),
                            CarbonImmutable::instance($quote->ends_at)->setTimezone((string) config('app.timezone', 'UTC')),
                            scope: $quote->scope,
                            court: $court,
                            allowEndAtMidnight: true,
                        );
                    } catch (InvalidArgumentException $exception) {
                        throw new VenueBookingTransitionException(
                            'Выбранное время больше недоступно. Выберите новый интервал.',
                            'BOOKING_CONFLICT',
                        );
                    }

                    $requiresPayment = (bool) data_get($quote->snapshot, 'policy.requires_payment', false);
                    $booking = VenueBooking::query()->create([
                        'public_id' => (string) Str::uuid(),
                        'flow' => 'rental',
                        'venue_id' => $quote->venue_id,
                        'venue_court_id' => $quote->venue_court_id,
                        'event_id' => null,
                        'created_by_actor_id' => $representative?->id ?? $actor->id,
                        'pre_request_id' => $preRequest?->id,
                        'created_by_representative_id' => $representative?->id,
                        'proposal_expires_at' => $preRequest === null ? null : $quote->valid_until,
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
                    $this->personalPricing->redeem($quote, $booking, $user);
                    $booking->parties()->create([
                        'user_id' => $user->id,
                        'role' => VenueBookingPartyRole::APPLICANT,
                    ]);
                    $this->lifecycle->recordRequested($booking, $representative ?? $actor);

                    $this->outbox->record($booking->id, VenueBookingRequested::class);

                    return $booking;
                });
            },
            $idempotencyKey,
            $correlationId,
        );

        return $booking->fresh(['transitions', 'parties', 'court']);
    }
}
