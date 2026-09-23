<?php

namespace App\Modules\Ai\Application\Services;

use App\Modules\Ai\Domain\Models\PlayerCharacterGeneration;
use App\Modules\Finance\Application\UseCases\CreditWalletHandler;
use App\Modules\Finance\Application\UseCases\DebitWalletHandler;
use App\Modules\Finance\Domain\Enums\WalletOperationTypeEnum;
use App\Modules\Finance\Domain\Enums\WalletSpendingPolicyEnum;
use App\Modules\Finance\Domain\Models\Wallet;
use App\Modules\Finance\Domain\Models\WalletOperation;
use App\Modules\Identity\Domain\Models\User;

final readonly class PlayerCharacterGenerationBilling
{
    private const REFERENCE_TYPE = 'player_character_generation';

    public function __construct(
        private DebitWalletHandler $debits,
        private CreditWalletHandler $credits,
    ) {}

    public function charge(
        Wallet $wallet,
        User $user,
        PlayerCharacterGeneration $generation,
        int $amountMinor,
    ): ?WalletOperation {
        if ($amountMinor <= 0) {
            return null;
        }

        return $this->debits->handle(
            wallet: $wallet,
            amountMinor: $amountMinor,
            operationType: WalletOperationTypeEnum::INTERNAL_SERVICE_PAYMENT,
            idempotencyKey: $this->chargeKey($generation),
            spendingPolicy: WalletSpendingPolicyEnum::BONUS_THEN_REAL,
            performedByUserId: $user->id,
            referenceType: self::REFERENCE_TYPE,
            referenceKey: $generation->public_id,
            metadata: [
                'service_code' => 'avatar_generation',
                'generation_id' => $generation->public_id,
            ],
        );
    }

    public function refund(PlayerCharacterGeneration $generation): void
    {
        $charge = WalletOperation::query()
            ->where('idempotency_key', $this->chargeKey($generation))
            ->with('entries.wallet')
            ->first();

        if ($charge === null) {
            return;
        }

        foreach ($charge->entries as $entry) {
            $amountMinor = -(int) $entry->amount_minor;
            if ($amountMinor <= 0 || $entry->wallet === null) {
                continue;
            }

            $this->credits->handle(
                wallet: $entry->wallet,
                balanceType: $entry->balance_type,
                amountMinor: $amountMinor,
                operationType: WalletOperationTypeEnum::REFUND,
                idempotencyKey: $this->refundKey($generation, $entry->balance_type->value),
                performedByUserId: $generation->user_id,
                referenceType: self::REFERENCE_TYPE,
                referenceKey: $generation->public_id,
                metadata: [
                    'service_code' => 'avatar_generation',
                    'generation_id' => $generation->public_id,
                    'refund_of_operation_id' => $charge->id,
                ],
            );
        }
    }

    private function chargeKey(PlayerCharacterGeneration $generation): string
    {
        return 'pcg:'.$generation->public_id.':charge';
    }

    private function refundKey(PlayerCharacterGeneration $generation, string $balanceType): string
    {
        return 'pcg:'.$generation->public_id.':refund:'.$balanceType;
    }
}
