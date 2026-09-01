<?php

namespace App\Modules\VenueBooking\Application\UseCases;

use App\Modules\Audit\Domain\Models\AuditLog;
use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\VenueBooking\Application\Services\BookingContributionAccess;
use App\Modules\VenueBooking\Application\Services\MinorAmountParser;
use App\Modules\VenueBooking\Domain\Enums\BookingContributionStatus;
use App\Modules\VenueBooking\Domain\Enums\VenueBookingPaymentState;
use App\Modules\VenueBooking\Domain\Exceptions\VenueBookingTransitionException;
use App\Modules\VenueBooking\Domain\Models\BookingContributionCommitment;
use App\Modules\VenueBooking\Domain\Models\VenueBooking;
use App\Support\Features\FeatureFlags;
use App\Support\Features\VenueRentalFeature;

final readonly class GetContributionSummaryHandler
{
    public function __construct(private BookingContributionAccess $access, private MinorAmountParser $amounts, private FeatureFlags $features) {}

    /** @return array<string, mixed> */
    public function handle(VenueBooking $booking, Actor $actor, ?string $auditRoute = null): array
    {
        $this->features->ensureEnabled(VenueRentalFeature::CONTRIBUTIONS);
        $this->access->assertCanViewSummary($actor, $booking);

        $currency = strtoupper((string) data_get($booking->quote_snapshot, 'pricing.currency', ''));
        $target = (int) data_get($booking->quote_snapshot, 'pricing.amount_minor', -1);
        if ($target < 1 || preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new VenueBookingTransitionException('В расчёте отсутствует корректная стоимость аренды.', 'INVALID_QUOTE_SNAPSHOT');
        }

        $query = BookingContributionCommitment::query()
            ->where('venue_booking_id', $booking->id)
            ->where('status', BookingContributionStatus::ACTIVE);
        $committed = (int) (clone $query)->sum('amount_minor');
        $userId = $actor->user?->canonical()->id;
        $own = $userId === null ? null : (clone $query)->where('user_id', $userId)->first();
        $details = collect();

        if ($this->access->isSuperadmin($actor)) {
            $details = (clone $query)->orderBy('id')->get();
            AuditLog::query()->create([
                'actor_id' => $actor->id,
                'auditable_type' => VenueBooking::class,
                'auditable_id' => $booking->id,
                'event' => 'contribution_details_viewed_for_support',
                'old_values' => [],
                'new_values' => [],
                'metadata' => ['route' => $auditRoute],
            ]);
        } elseif ($this->access->isOrganizer($actor, $booking)) {
            $details = (clone $query)
                ->where(fn ($builder) => $builder->where('user_id', $userId)->orWhere('share_with_organizer', true))
                ->orderBy('id')
                ->get();
        } elseif ($own !== null) {
            $details = collect([$own]);
        }

        return [
            'booking_id' => $booking->public_id,
            'currency' => $currency,
            'currency_exponent' => $this->amounts->exponent($currency),
            'target_minor' => $target,
            'committed_minor' => $committed,
            'confirmed_minor' => $booking->payment_state === VenueBookingPaymentState::CONFIRMED ? $target : 0,
            'is_open' => $this->isOpen($booking),
            'own_commitment' => $own === null ? null : $this->detail($own),
            'visible_commitments' => $details->map(fn (BookingContributionCommitment $commitment): array => $this->detail($commitment))->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function detail(BookingContributionCommitment $commitment): array
    {
        return [
            'commitment_id' => $commitment->public_id,
            'user_id' => $commitment->user_id,
            'amount_minor' => $commitment->amount_minor,
            'currency' => $commitment->currency,
            'share_with_organizer' => $commitment->share_with_organizer,
            'committed_at' => $commitment->committed_at->toIso8601String(),
        ];
    }

    private function isOpen(VenueBooking $booking): bool
    {
        return ($booking->status->value === 'held' && $booking->effective_protection_until?->isFuture())
            || ($booking->status->value === 'confirmed' && $booking->starts_at->isFuture());
    }
}
