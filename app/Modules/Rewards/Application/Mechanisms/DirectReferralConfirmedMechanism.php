<?php

namespace App\Modules\Rewards\Application\Mechanisms;

use App\Modules\Acquisition\Domain\Models\ReferralAttribution;
use App\Modules\Finance\Domain\Enums\WalletOperationTypeEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Rewards\Application\Contracts\RewardMechanism;
use App\Modules\Rewards\Application\Data\RewardMechanismContext;
use App\Modules\Rewards\Application\Data\RewardMechanismDecision;
use App\Modules\Rewards\Domain\Models\RewardVersion;

final class DirectReferralConfirmedMechanism implements RewardMechanism
{
    public function code(): string
    {
        return 'referral_user_confirmed';
    }

    public function label(): string
    {
        return 'Подтверждение прямого приглашённого пользователя';
    }

    public function walletOperationType(): WalletOperationTypeEnum
    {
        return WalletOperationTypeEnum::REFERRAL_REWARD;
    }

    public function parameterRules(): array
    {
        return [];
    }

    public function evaluate(
        RewardMechanismContext $context,
        RewardVersion $version,
    ): RewardMechanismDecision {
        $confirmedUserId = (int) ($context->payload['confirmed_user_id'] ?? 0);
        $confirmed = User::query()->find($confirmedUserId)?->canonical();

        if ($confirmed === null || ! $confirmed->isConfirmed()) {
            return RewardMechanismDecision::rejected('Подтверждённый пользователь не найден.');
        }

        $attribution = ReferralAttribution::query()
            ->whereIn('referred_user_id', $confirmed->identityIds())
            ->orderBy('id')
            ->first();

        if ($attribution === null) {
            return RewardMechanismDecision::rejected('У пользователя нет реферальной атрибуции.');
        }

        $referrer = User::query()->find($attribution->referrer_user_id)?->canonical();

        if (
            $referrer === null
            || $referrer->trashed()
            || $referrer->isBlocked()
            || ! $referrer->isConfirmed()
            || $referrer->isSameIdentity($confirmed)
        ) {
            return RewardMechanismDecision::rejected('Пригласивший пользователь не подходит для начисления.');
        }

        return RewardMechanismDecision::eligible((int) $referrer->id);
    }
}
