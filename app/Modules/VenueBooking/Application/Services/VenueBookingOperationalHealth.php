<?php

namespace App\Modules\VenueBooking\Application\Services;

use App\Modules\Event\Domain\Enums\VenueBookingStatusEnum;
use App\Modules\VenueBooking\Domain\Enums\VenueBookingPaymentState;
use App\Modules\VenueBooking\Domain\Models\VenueBooking;
use App\Modules\VenueBooking\Domain\Models\VenueBookingOutboxMessage;
use App\Modules\VenueBooking\Domain\Models\VenueBookingPaymentAttempt;
use App\Modules\VenueBooking\Domain\Models\VenueBookingPaymentWebhook;
use Illuminate\Support\Facades\Cache;

final class VenueBookingOperationalHealth
{
    /** @return array<string, mixed> */
    public function snapshot(): array
    {
        $now = now();
        $oldestOverdueHold = VenueBooking::query()->where('flow', 'rental')->where('status', VenueBookingStatusEnum::HELD)
            ->where('effective_protection_until', '<', $now)->min('effective_protection_until');
        $oldestOutbox = VenueBookingOutboxMessage::query()->whereIn('status', ['pending', 'processing'])->min('created_at');

        return [
            'generated_at' => $now->utc()->toIso8601String(),
            'overdue_holds' => VenueBooking::query()->where('flow', 'rental')->where('status', VenueBookingStatusEnum::HELD)->where('effective_protection_until', '<', $now)->count(),
            'hold_expiry_lag_seconds' => $oldestOverdueHold === null ? 0 : $now->diffInSeconds($oldestOverdueHold),
            'stale_payment_intents' => VenueBookingPaymentAttempt::query()->whereIn('status', [VenueBookingPaymentState::WINDOW_OPEN, VenueBookingPaymentState::CLAIMED])->where('window_expires_at', '<', $now)->count(),
            'failed_webhooks_24h' => VenueBookingPaymentWebhook::query()->whereIn('status', ['failed', 'rejected'])->where('created_at', '>=', $now->copy()->subDay())->count(),
            'outbox_backlog' => VenueBookingOutboxMessage::query()->whereIn('status', ['pending', 'processing'])->count(),
            'outbox_lag_seconds' => $oldestOutbox === null ? 0 : $now->diffInSeconds($oldestOutbox),
            'conflicts' => $this->metric('metrics:venue_booking:conflicts'),
            'deadlock_retries' => $this->metric('metrics:venue_booking:deadlock_retry'),
        ];
    }

    private function metric(string $key): ?int
    {
        try {
            return (int) Cache::get($key, 0);
        } catch (\Throwable) {
            return null;
        }
    }
}
