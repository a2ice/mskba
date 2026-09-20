<?php

namespace App\Modules\Finance\Domain\Enums;

enum WalletOperationTypeEnum: string
{
    case TOP_UP = 'top_up';
    case REFERRAL_REWARD = 'referral_reward';
    case INTERNAL_SERVICE_PAYMENT = 'internal_service_payment';
    case REFUND = 'refund';

    public function label(): string
    {
        return match ($this) {
            self::TOP_UP => 'Пополнение',
            self::REFERRAL_REWARD => 'Реферальное вознаграждение',
            self::INTERNAL_SERVICE_PAYMENT => 'Оплата внутренней услуги',
            self::REFUND => 'Возврат',
        };
    }

    public function supportsCredit(): bool
    {
        return in_array($this, [
            self::TOP_UP,
            self::REFERRAL_REWARD,
            self::REFUND,
        ], true);
    }

    public function supportsDebit(): bool
    {
        return $this === self::INTERNAL_SERVICE_PAYMENT;
    }
}
