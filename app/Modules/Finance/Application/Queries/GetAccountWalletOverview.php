<?php

namespace App\Modules\Finance\Application\Queries;

use App\Modules\Finance\Application\Services\SuperadminWalletBootstrapBonus;
use App\Modules\Finance\Application\Services\WalletOwnerResolver;
use App\Modules\Finance\Domain\Enums\WalletBalanceTypeEnum;
use App\Modules\Finance\Domain\Enums\WalletOperationStatusEnum;
use App\Modules\Finance\Domain\Enums\WalletOperationTypeEnum;
use App\Modules\Finance\Domain\Enums\WalletOwnerTypeEnum;
use App\Modules\Finance\Domain\Enums\WalletTypeEnum;
use App\Modules\Finance\Domain\Models\Wallet;
use App\Modules\Finance\Domain\Models\WalletOperation;
use App\Modules\Identity\Domain\Models\User;

final readonly class GetAccountWalletOverview
{
    public function __construct(
        private WalletOwnerResolver $owners,
        private SuperadminWalletBootstrapBonus $bootstrapBonus,
    ) {}

    /**
     * @return array{
     *     walletExists: bool,
     *     currency: string,
     *     totalBalanceMinor: int,
     *     realBalanceMinor: int,
     *     bonusBalanceMinor: int,
     *     bootstrapBonusAvailable: bool,
     *     operations: array<int, array{
     *         id: int,
     *         type: string,
     *         label: string,
     *         description: ?string,
     *         completedAt: mixed,
     *         realDeltaMinor: int,
     *         bonusDeltaMinor: int,
     *         totalDeltaMinor: int
     *     }>
     * }
     */
    public function handle(User $user, int $historyLimit = 50): array
    {
        $ownerId = $this->owners->canonicalOwnerId(WalletOwnerTypeEnum::USER, (int) $user->id);

        $wallet = Wallet::query()
            ->where('owner_type', WalletOwnerTypeEnum::USER->value)
            ->where('owner_id', $ownerId)
            ->where('type', WalletTypeEnum::MAIN->value)
            ->where('currency', 'RUB')
            ->first();

        if ($wallet === null) {
            return [
                'walletExists' => false,
                'currency' => 'RUB',
                'totalBalanceMinor' => 0,
                'realBalanceMinor' => 0,
                'bonusBalanceMinor' => 0,
                'bootstrapBonusAvailable' => $this->bootstrapBonus->available($user),
                'operations' => [],
            ];
        }

        $operations = WalletOperation::query()
            ->where('status', WalletOperationStatusEnum::COMPLETED->value)
            ->whereHas('entries', fn ($query) => $query->where('wallet_id', $wallet->id))
            ->with([
                'entries' => fn ($query) => $query
                    ->where('wallet_id', $wallet->id)
                    ->orderBy('id'),
            ])
            ->orderByDesc('completed_at')
            ->orderByDesc('id')
            ->limit(min(100, max(1, $historyLimit)))
            ->get()
            ->map(function (WalletOperation $operation) use ($ownerId): array {
                $realDelta = (int) $operation->entries
                    ->filter(fn ($entry): bool => $entry->balance_type === WalletBalanceTypeEnum::REAL)
                    ->sum('amount_minor');
                $bonusDelta = (int) $operation->entries
                    ->filter(fn ($entry): bool => $entry->balance_type === WalletBalanceTypeEnum::BONUS)
                    ->sum('amount_minor');

                return [
                    'id' => (int) $operation->id,
                    'type' => $operation->type->value,
                    'label' => $operation->type->label(),
                    'description' => $this->operationDescription($operation, $ownerId),
                    'completedAt' => $operation->completed_at,
                    'realDeltaMinor' => $realDelta,
                    'bonusDeltaMinor' => $bonusDelta,
                    'totalDeltaMinor' => $realDelta + $bonusDelta,
                ];
            })
            ->all();

        return [
            'walletExists' => true,
            'currency' => $wallet->currency,
            'totalBalanceMinor' => $wallet->totalBalanceMinor(),
            'realBalanceMinor' => (int) $wallet->real_balance_minor,
            'bonusBalanceMinor' => (int) $wallet->bonus_balance_minor,
            'bootstrapBonusAvailable' => $this->bootstrapBonus->available($user),
            'operations' => $operations,
        ];
    }

    private function operationDescription(WalletOperation $operation, int $ownerId): ?string
    {
        if ($operation->type === WalletOperationTypeEnum::USER_TRANSFER) {
            $senderId = (int) ($operation->metadata['sender_user_id'] ?? 0);
            $senderHandle = trim((string) ($operation->metadata['sender_handle'] ?? ''));
            $recipientHandle = trim((string) ($operation->metadata['recipient_handle'] ?? ''));

            if ($senderId === $ownerId && $recipientHandle !== '') {
                return '→ '.$recipientHandle;
            }

            if ($senderId !== $ownerId && $senderHandle !== '') {
                return '← '.$senderHandle;
            }
        }

        $description = trim((string) ($operation->metadata['description'] ?? ''));

        return $description !== '' ? $description : null;
    }
}
