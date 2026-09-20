<?php

namespace App\Modules\Finance\Application\Services;

use App\Modules\Finance\Domain\Enums\WalletOperationStatusEnum;
use App\Modules\Finance\Domain\Enums\WalletOperationTypeEnum;
use App\Modules\Finance\Domain\Exceptions\WalletException;
use App\Modules\Finance\Domain\Exceptions\WalletIdempotencyException;
use App\Modules\Finance\Domain\Models\Wallet;
use App\Modules\Finance\Domain\Models\WalletOperation;
use BackedEnum;
use Closure;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

final class IdempotentWalletMutation
{
    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $metadata
     * @param Closure(Wallet, WalletOperation): void $callback
     */
    public function execute(
        Wallet $wallet,
        WalletOperationTypeEnum $operationType,
        string $idempotencyKey,
        array $payload,
        Closure $callback,
        ?int $performedByUserId = null,
        ?string $referenceType = null,
        ?string $referenceKey = null,
        array $metadata = [],
    ): WalletOperation {
        if ($idempotencyKey === '') {
            throw new WalletException('Ключ идемпотентности финансовой операции обязателен.');
        }

        $requestHash = hash('sha256', json_encode($this->canonicalize([
            'wallet_id' => (int) $wallet->id,
            'operation_type' => $operationType,
            'performed_by_user_id' => $performedByUserId,
            'reference_type' => $referenceType,
            'reference_key' => $referenceKey,
            'payload' => $payload,
            'metadata' => $metadata,
        ]), JSON_THROW_ON_ERROR));

        return DB::transaction(function () use (
            $wallet,
            $operationType,
            $idempotencyKey,
            $requestHash,
            $payload,
            $callback,
            $performedByUserId,
            $referenceType,
            $referenceKey,
            $metadata,
        ): WalletOperation {
            $lockedWallet = Wallet::query()->whereKey($wallet->id)->lockForUpdate()->firstOrFail();
            $now = now();

            WalletOperation::query()->insertOrIgnore([
                'type' => $operationType->value,
                'idempotency_key' => $idempotencyKey,
                'request_hash' => $requestHash,
                'performed_by_user_id' => $performedByUserId,
                'reference_type' => $referenceType,
                'reference_key' => $referenceKey,
                'metadata' => $metadata === [] ? null : json_encode($metadata, JSON_THROW_ON_ERROR),
                'status' => WalletOperationStatusEnum::PROCESSING->value,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $operation = WalletOperation::query()
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->firstOrFail();

            if ($operation->type !== $operationType || $operation->request_hash !== $requestHash) {
                throw new WalletIdempotencyException;
            }

            if ($operation->status === WalletOperationStatusEnum::COMPLETED) {
                return $operation->load('entries');
            }

            if ($operation->status !== WalletOperationStatusEnum::PROCESSING) {
                throw new WalletException('Финансовая операция временно недоступна.');
            }

            $callback($lockedWallet, $operation);

            $operation->forceFill([
                'status' => WalletOperationStatusEnum::COMPLETED,
                'completed_at' => now(),
            ])->save();

            return $operation->fresh('entries');
        });
    }

    private function canonicalize(mixed $value): mixed
    {
        if ($value instanceof BackedEnum) {
            return $value->value;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
    }
}
