<?php

namespace App\Modules\Rewards\Application\Mechanisms;

use App\Modules\Acquisition\Domain\Models\ReferralAttribution;
use App\Modules\Finance\Domain\Enums\WalletOperationTypeEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Rewards\Application\Contracts\RewardMechanism;
use App\Modules\Rewards\Application\Data\RewardMechanismContext;
use App\Modules\Rewards\Application\Data\RewardMechanismDecision;
use App\Modules\Rewards\Domain\Models\RewardVersion;

final class SecondLevelReferralConfirmedMechanism implements RewardMechanism
{
    public function code(): string
    {
        return 'referral_second_level_user_confirmed';
    }

    public function label(): string
    {
        return 'Подтверждение пользователя второго уровня A → B → C';
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
            return RewardMechanismDecision::rejected('Подтверждённый пользователь второго уровня не найден.');
        }

        $directAttribution = ReferralAttribution::query()
            ->whereIn('referred_user_id', $confirmed->identityIds())
            ->orderBy('id')
            ->first();

        if ($directAttribution === null) {
            return RewardMechanismDecision::rejected('Первый уровень цепочки не найден.');
        }

        $directReferrer = User::query()->find($directAttribution->referrer_user_id)?->canonical();

        if (
            $directReferrer === null
            || $directReferrer->trashed()
            || $directReferrer->isBlocked()
            || ! $directReferrer->isConfirmed()
        ) {
            return RewardMechanismDecision::rejected('Прямой пригласивший должен быть подтверждён.');
        }

        $rootAttribution = ReferralAttribution::query()
            ->whereIn('referred_user_id', $directReferrer->identityIds())
            ->orderBy('id')
            ->first();

        if ($rootAttribution === null) {
            return RewardMechanismDecision::rejected('Второй уровень цепочки не найден.');
        }

        $rootReferrer = User::query()->find($rootAttribution->referrer_user_id)?->canonical();

        if (
            $rootReferrer === null
            || $rootReferrer->trashed()
            || $rootReferrer->isBlocked()
            || ! $rootReferrer->isConfirmed()
            || $rootReferrer->isSameIdentity($directReferrer)
            || $rootReferrer->isSameIdentity($confirmed)
        ) {
            return RewardMechanismDecision::rejected('Первоначальный пригласивший не подходит для начисления.');
        }

        return RewardMechanismDecision::eligible((int) $rootReferrer->id);
    }
}
