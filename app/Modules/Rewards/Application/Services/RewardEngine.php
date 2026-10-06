<?php

namespace App\Modules\Rewards\Application\Services;

use App\Modules\Finance\Application\UseCases\CreditWalletHandler;
use App\Modules\Finance\Application\UseCases\EnsureWalletHandler;
use App\Modules\Finance\Domain\Enums\WalletBalanceTypeEnum;
use App\Modules\Finance\Domain\Enums\WalletOwnerTypeEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Rewards\Application\Contracts\RewardMechanism;
use App\Modules\Rewards\Application\Data\RewardMechanismContext;
use App\Modules\Rewards\Domain\Enums\RewardGrantStatusEnum;
use App\Modules\Rewards\Domain\Models\Reward;
use App\Modules\Rewards\Domain\Models\RewardGrant;
use App\Modules\Rewards\Domain\Models\RewardVersion;
use Illuminate\Support\Facades\DB;

final class RewardEngine
{
    public function __construct(
        private readonly RewardMechanismRegistry $mechanisms,
        private readonly EnsureWalletHandler $ensureWallet,
        private readonly CreditWalletHandler $creditWallet,
    ) {}

    public function process(string $rewardCode, RewardMechanismContext $context): ?RewardGrant
    {
        $reward = Reward::withTrashed()
            ->where('code', $rewardCode)
            ->first();

        if ($reward === null) {
            return null;
        }

        $existing = RewardGrant::query()
            ->where('reward_id', $reward->id)
            ->where('business_fact_type', $context->businessFactType)
            ->where('business_fact_key', $context->businessFactKey)
            ->orderBy('id')
            ->first();

        if ($existing !== null) {
            return $this->settle($existing);
        }

        if ($reward->trashed() || ! $reward->is_enabled) {
            return null;
        }

        $version = $reward->currentVersion()->first();

        if (
            ! $version instanceof RewardVersion
            || $version->mechanism_code === null
            || $version->mechanism_code !== $reward->mechanism_code
        ) {
            return null;
        }

        $mechanism = $this->mechanisms->find($version->mechanism_code);

        if (! $mechanism instanceof RewardMechanism) {
            return null;
        }

        $decision = $mechanism->evaluate($context, $version);

        if (! $decision->eligible || $decision->recipientUserId === null) {
            return null;
        }

        $recipient = User::query()->find($decision->recipientUserId)?->canonical();

        if ($recipient === null || $recipient->trashed() || $recipient->isBlocked()) {
            return null;
        }

        $grant = $this->recordGrant(
            reward: $reward,
            version: $version,
            mechanism: $mechanism,
            context: $context,
            recipient: $recipient,
        );

        return $this->settle($grant);
    }

    public function settle(RewardGrant $grant): RewardGrant
    {
        $grant->refresh();

        if ($grant->status === RewardGrantStatusEnum::COMPLETED) {
            return $grant;
        }

        $recipient = User::query()->find($grant->recipient_user_id)?->canonical();

        if ($recipient === null || $recipient->trashed() || $recipient->isBlocked()) {
            return $grant;
        }

        $wallet = $this->ensureWallet->handle(
            ownerType: WalletOwnerTypeEnum::USER,
            ownerId: (int) $recipient->id,
        );

        $operation = $this->creditWallet->handle(
            wallet: $wallet,
            balanceType: WalletBalanceTypeEnum::BONUS,
            amountMinor: (int) $grant->amount_minor,
            operationType: $grant->wallet_operation_type,
            idempotencyKey: 'reward-grant-'.$grant->id,
            performedByUserId: null,
            referenceType: 'reward_grant',
            referenceKey: (string) $grant->id,
            metadata: [
                'reward_id' => (int) $grant->reward_id,
                'reward_version_id' => (int) $grant->reward_version_id,
                'mechanism_code' => $grant->mechanism_code,
                'business_fact_type' => $grant->business_fact_type,
                'business_fact_key' => $grant->business_fact_key,
            ],
        );

        return DB::transaction(function () use ($grant, $operation): RewardGrant {
            $locked = RewardGrant::query()
                ->whereKey($grant->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status !== RewardGrantStatusEnum::COMPLETED) {
                $locked->forceFill([
                    'status' => RewardGrantStatusEnum::COMPLETED,
                    'wallet_operation_id' => $operation->id,
                    'completed_at' => now(),
                ])->save();
            }

            return $locked->refresh();
        });
    }

    private function recordGrant(
        Reward $reward,
        RewardVersion $version,
        RewardMechanism $mechanism,
        RewardMechanismContext $context,
        User $recipient,
    ): RewardGrant {
        return DB::transaction(function () use ($reward, $version, $mechanism, $context, $recipient): RewardGrant {
            $now = now();
            $identity = [
                'reward_id' => (int) $reward->id,
                'business_fact_type' => $context->businessFactType,
                'business_fact_key' => $context->businessFactKey,
                'recipient_user_id' => (int) $recipient->id,
            ];

            RewardGrant::query()->insertOrIgnore([
                ...$identity,
                'reward_version_id' => (int) $version->id,
                'mechanism_code' => $mechanism->code(),
                'wallet_operation_type' => $mechanism->walletOperationType()->value,
                'amount_minor' => (int) $version->amount_minor,
                'currency' => $version->currency,
                'status' => RewardGrantStatusEnum::PENDING->value,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return RewardGrant::query()
                ->where($identity)
                ->lockForUpdate()
                ->firstOrFail();
        });
    }
}
