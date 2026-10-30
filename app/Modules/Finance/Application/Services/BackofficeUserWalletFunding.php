<?php

namespace App\Modules\Finance\Application\Services;

use App\Modules\Audit\Domain\Models\AuditLog;
use App\Modules\Finance\Application\DTO\WalletTransferResult;
use App\Modules\Finance\Application\UseCases\TransferUserWalletBalanceHandler;
use App\Modules\Finance\Domain\Enums\WalletBalanceTypeEnum;
use App\Modules\Finance\Domain\Exceptions\WalletException;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Identity\Application\Services\GlobalOperationalPermissionManager;
use App\Modules\Identity\Application\Services\UserOperationalPermissionChecker;
use App\Modules\Identity\Domain\Enums\UserOperationalPermissionEnum as Permission;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Bonus awards are funded from a real superadmin wallet, not minted.
 * A single existing double-entry transfer records BOTH ledgers. Delegated
 * operators may spend from a specifically selected superadmin source only
 * when individually granted the sensitive permission.
 */
final readonly class BackofficeUserWalletFunding
{
    public function __construct(
        private UserOperationalPermissionChecker $permissions,
        private GlobalOperationalPermissionManager $management,
        private TransferUserWalletBalanceHandler $transfers,
    ) {}

    public function canFund(User $actor, User $recipient): bool
    {
        $actor = $actor->canonical();
        $recipient = $recipient->canonical();

        return $actor->isConfirmed() && ! $actor->isBlocked() && ! $actor->trashed()
            && $recipient->isConfirmed() && ! $recipient->isBlocked() && ! $recipient->trashed()
            && $recipient->system_role !== UserSystemRoleEnum::SUPERADMIN
            && $recipient->system_role !== UserSystemRoleEnum::SYSTEM
            && $this->management->mayManage($actor, $recipient)
            && $this->permissions->allows($actor, Permission::WALLET_USER_BALANCE_CREDIT);
    }

    /** @return array<int,string> */
    public function sourceOptions(User $actor): array
    {
        $actor = $actor->canonical();

        if ($actor->system_role === UserSystemRoleEnum::SUPERADMIN) {
            return [$actor->id => $actor->username.' (мой бонусный баланс)'];
        }

        if (! $actor->isConfirmed() || ! $this->permissions->allows($actor, Permission::WALLET_USER_BALANCE_CREDIT)) {
            return [];
        }

        return User::query()
            ->whereNull('canonical_user_id')
            ->where('system_role', UserSystemRoleEnum::SUPERADMIN->value)
            ->where('status', 'confirmed')
            ->orderBy('id')->limit(100)
            ->get()->reject(fn (User $source): bool => $source->isBlocked() || $source->trashed())
            ->mapWithKeys(fn (User $source): array => [$source->id => $source->username.' (#'.$source->id.')'])
            ->all();
    }

    public function fund(
        User $actor,
        User $recipient,
        int $sourceSuperadminId,
        int $amountMinor,
        string $reason,
        string $idempotencyKey,
    ): WalletTransferResult {
        $actor = $actor->canonical();
        $recipient = $recipient->canonical();
        $reason = trim($reason);

        if (! $this->canFund($actor, $recipient)) {
            throw new AuthorizationException('Нет права пополнять пользовательский баланс.');
        }

        if (mb_strlen($reason) < 10 || mb_strlen($reason) > 500) {
            throw ValidationException::withMessages(['reason' => 'Укажите основание начисления (10–500 символов).']);
        }

        if ($amountMinor <= 0 || $amountMinor > 99_999_999_999) {
            throw ValidationException::withMessages(['amount' => 'Укажите положительную сумму не более 999 999 999,99 ₽.']);
        }

        if (! in_array($sourceSuperadminId, array_keys($this->sourceOptions($actor)), true)) {
            throw new AuthorizationException('Недоступен кошелёк источника начисления.');
        }

        return DB::transaction(function () use (
            $actor, $recipient, $sourceSuperadminId, $amountMinor, $reason, $idempotencyKey,
        ): WalletTransferResult {
            // Re-check current privileges and both accounts at the mutation
            // boundary, not only when constructing the Filament modal.
            $actor = User::query()->findOrFail($actor->id);
            $recipient = User::query()->findOrFail($recipient->id);
            if (! $this->canFund($actor, $recipient)) {
                throw new AuthorizationException('Нет права пополнять пользовательский баланс.');
            }
            $source = User::query()->findOrFail($sourceSuperadminId);
            if ($source->canonical()->id !== $source->id
                || $source->system_role !== UserSystemRoleEnum::SUPERADMIN
                || ! $source->isConfirmed() || $source->isBlocked() || $source->trashed()
                || ($actor->system_role === UserSystemRoleEnum::SUPERADMIN && $source->id !== $actor->id)) {
                throw new AuthorizationException('Недоступен кошелёк источника начисления.');
            }

            $result = $this->transfers->handle(
                sender: $source,
                recipient: $recipient,
                amountMinor: $amountMinor,
                balanceType: WalletBalanceTypeEnum::BONUS,
                idempotencyKey: $idempotencyKey,
                performedBy: $actor,
                adminReason: $reason,
            );

            if (! $result->replayed) {
                AuditLog::query()->create([
                    'actor_id' => app(CurrentActorResolver::class)->resolveForRequest(request())?->id,
                    'auditable_type' => User::class,
                    'auditable_id' => $recipient->id,
                    'event' => 'backoffice_user_bonus_funded',
                    'old_values' => [],
                    'new_values' => ['wallet_operation_id' => $result->operation->id, 'amount_minor' => $amountMinor],
                    'metadata' => [
                        'operator_user_id' => $actor->id,
                        'source_superadmin_user_id' => $source->id,
                        'recipient_user_id' => $recipient->id,
                        'reason' => $reason,
                    ],
                ]);
            }

            return $result;
        });
    }
}
