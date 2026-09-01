<?php

namespace App\Modules\VenueBooking\Application\Services;

use App\Modules\VenueBooking\Domain\Enums\VenueBookingPaymentState;
use App\Modules\VenueBooking\Domain\Models\VenueBookingPaymentAttempt;
use App\Modules\VenueBooking\Infrastructure\Jobs\ReconcilePaymentIntentJob;
use App\Support\Features\FeatureFlags;
use App\Support\Features\VenueRentalFeature;

final readonly class PaymentReconciliationDispatcher
{
    public function __construct(private FeatureFlags $features) {}

    public function dispatchStale(int $batch = 100): int
    {
        if (! $this->features->enabled(VenueRentalFeature::PAYMENT_PORT)) {
            return 0;
        }

        $ids = VenueBookingPaymentAttempt::query()
            ->where('provider', '!=', 'external_manual')
            ->whereIn('status', [VenueBookingPaymentState::WINDOW_OPEN, VenueBookingPaymentState::CLAIMED])
            ->where(fn ($query) => $query->whereNull('provider_checked_at')->orWhere('provider_checked_at', '<', now()->subMinutes(5)))
            ->orderBy('id')->limit(min(500, max(1, $batch)))->pluck('id');
        foreach ($ids as $id) {
            ReconcilePaymentIntentJob::dispatch((int) $id);
        }

        return $ids->count();
    }
}
