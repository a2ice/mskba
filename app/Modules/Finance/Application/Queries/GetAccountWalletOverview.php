<?php

namespace App\Modules\Finance\Application\Queries;

use App\Modules\Finance\Application\Services\WalletOwnerResolver;
use App\Modules\Finance\Domain\Enums\WalletBalanceTypeEnum;
use App\Modules\Finance\Domain\Enums\WalletOperationStatusEnum;
use App\Modules\Finance\Domain\Enums\WalletOwnerTypeEnum;
use App\Modules\Finance\Domain\Enums\WalletTypeEnum;
use App\Modules\Finance\Domain\Models\Wallet;
use App\Modules\Finance\Domain\Models\WalletOperation;
use App\Modules\Identity\Domain\Models\User;

final readonly class GetAccountWalletOverview
{
    public function __construct(private WalletOwnerResolver $owners) {}

    /**
     * @return array{
     *     walletExists: bool,
     *     currency: string,
     *     totalBalanceMinor: int,
     *     realBalanceMinor: int,
     *     bonusBalanceMinor: int,
     *     operations: array<int, array{
     *         id: int,
     *         type: string,
     *         label: string,
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
            ->map(function (WalletOperation $operation): array {
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
            'operations' => $operations,
        ];
    }
}
