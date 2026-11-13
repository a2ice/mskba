<?php

namespace App\Modules\Rewards\Application\Mechanisms;

use App\Modules\Event\Domain\Enums\VenueBookingStatusEnum;
use App\Modules\Finance\Domain\Enums\WalletOperationTypeEnum;
use App\Modules\Rewards\Application\Contracts\RewardMechanism;
use App\Modules\Rewards\Application\Data\RewardMechanismContext;
use App\Modules\Rewards\Application\Data\RewardMechanismDecision;
use App\Modules\Rewards\Domain\Models\RewardVersion;
use App\Modules\Rewards\Domain\Models\VenueRewardOverride;
use App\Modules\VenueBooking\Domain\Enums\VenueBookingPaymentState;
use App\Modules\VenueBooking\Domain\Models\VenueBooking;

final class VenueRentalCompletedMechanism implements RewardMechanism
{
    public function code(): string
    {
        return 'venue_rental_completed';
    }

    public function label(): string
    {
        return 'Оплаченная завершённая аренда площадки';
    }

    public function walletOperationType(): WalletOperationTypeEnum
    {
        return WalletOperationTypeEnum::BONUS_GRANT;
    }

    public function parameterRules(): array
    {
        return [];
    }

    public function evaluate(
        RewardMechanismContext $context,
        RewardVersion $version,
    ): RewardMechanismDecision {
        $bookingId = (int) ($context->payload['booking_id'] ?? 0);
        $booking = VenueBooking::query()->with('requester')->find($bookingId);

        if ($booking === null
            || $booking->status !== VenueBookingStatusEnum::CONFIRMED
            || $booking->payment_state !== VenueBookingPaymentState::CONFIRMED
            || $booking->ends_at === null
            || $booking->ends_at->isFuture()
            || $booking->confirmed_at === null
            || $version->valid_from === null
            || $booking->confirmed_at->lt($version->valid_from)
            || (int) data_get($booking->quote_snapshot, 'pricing.amount_minor', 0) <= 0
            || data_get($booking->quote_snapshot, 'pricing.currency') !== 'RUB') {
            return RewardMechanismDecision::rejected('Нет завершённой подтверждённой оплаченной аренды.');
        }

        $recipient = $booking->requester?->canonical();
        if ($recipient === null || $recipient->trashed() || $recipient->isBlocked()) {
            return RewardMechanismDecision::rejected('Арендатор не найден или недоступен.');
        }

        $amountMinor = (int) $version->amount_minor;
        $override = VenueRewardOverride::query()
            ->where('venue_id', $booking->venue_id)
            ->where('reward_id', $version->reward_id)
            ->first();

        if ($override?->mode === 'disabled') {
            return RewardMechanismDecision::rejected('Кешбэк за аренду отключён для площадки.');
        }

        if ($override?->mode === 'custom') {
            $amountMinor = (int) $override->amount_minor;
        }

        if ($amountMinor <= 0) {
            return RewardMechanismDecision::rejected('Для площадки действует нулевой кешбэк.');
        }

        return RewardMechanismDecision::eligible((int) $recipient->id, $amountMinor);
    }
}
