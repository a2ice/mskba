<?php

namespace App\Modules\Contract\Application\Services;

use App\Modules\Contract\Domain\Enums\ContractMembershipScopeTypeEnum as Scope;
use App\Modules\Contract\Domain\Enums\ContractStatusEnum;
use App\Modules\Contract\Domain\Models\Contract;
use App\Modules\Identity\Application\Services\BackofficePermissionManager;
use App\Modules\Identity\Domain\Enums\UserOperationalPermissionEnum as Permission;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Team\Domain\Enums\TeamInvitationStatusEnum;
use App\Modules\Notification\Application\DTO\CreateUserNotificationDTO;
use App\Modules\Notification\Application\UseCases\CreateUserNotificationHandler;
use App\Modules\Notification\Domain\Enums\UserNotificationTypeEnum;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Administrative corrections are authorized by global backoffice rights.
 * Membership contracts never grant a Filament permission and never bypass
 * public consent or the separately verified commercial-venue workflow.
 */
final class BackofficeContractEditor
{
    public function canView(): bool
    {
        $user = auth('web')->user();
        $manager = app(BackofficePermissionManager::class);
        return $user !== null && $manager->allows($user, Permission::BACKOFFICE_ACCESS)
            && $manager->allows($user, Permission::BACKOFFICE_CONTRACT_VIEW);
    }

    public function canManage(): bool
    {
        $user = auth('web')->user();
        $manager = app(BackofficePermissionManager::class);
        return $user !== null && $manager->allows($user, Permission::BACKOFFICE_ACCESS)
            && $manager->allows($user, Permission::BACKOFFICE_CONTRACT_MANAGE);
    }

    public function isOwnerContract(Contract $contract): bool
    {
        return in_array($contract->membership?->access_level, ['owner', 'primary_manager'], true);
    }

    public function mayEditRights(Contract $contract): bool
    {
        $member = $contract->membership;
        return $member !== null
            && ($member->access_level === 'responsible'
                || ($member->scope_type === Scope::SPORTS_SECTION && $member->access_level === 'manager'))
            && $contract->comment === EntityContractManager::COMMENT_MARKER
            && app(EntityContractManager::class)->allPermissions($member->scope_type) !== [];
    }

    /** @return array<string, string> */
    public function permissionOptions(Contract $contract): array
    {
        $member = $contract->membership;
        if ($member === null) return [];

        return collect(app(EntityContractManager::class)->permissionCatalog($member->scope_type))
            ->mapWithKeys(static fn (\BackedEnum $p): array => [$p->value => $p->label()])
            ->all();
    }

    public function update(Contract $record, array $input): Contract
    {
        abort_unless($this->canManage(), 403);
        $data = Validator::make($input, [
            'name' => ['required', 'string', 'min:2', 'max:150'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'expires_at' => ['nullable', 'date'],
            'status' => ['required', Rule::in([ContractStatusEnum::ACTIVE->value, ContractStatusEnum::INACTIVE->value])],
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => ['required', 'string', 'distinct'],
            'change_reason' => ['required', 'string', 'min:5', 'max:2000'],
        ])->validate();

        return DB::transaction(function () use ($record, $data): Contract {
            $contract = Contract::query()->with(['membership', 'permissions'])
                ->whereKey($record->id)->lockForUpdate()->firstOrFail();
            $before = [
                'name' => $contract->name,
                'notes' => $contract->notes,
                'status' => $contract->status->value,
                'expires_at' => $contract->expires_at?->toIso8601String(),
                'permissions' => $contract->permissions->pluck('permission')->sort()->values()->all(),
            ];

            if ($this->isOwnerContract($contract)) {
                // Ownership is transferred via the acceptance workflow; an
                // owner contract cannot be revoked or shortened as a normal edit.
                if ($data['status'] !== $before['status']
                    || isset($data['expires_at']) && $data['expires_at'] !== '') {
                    throw ValidationException::withMessages(['status' => 'Для смены владельца используйте передачу владения.']);
                }
            } elseif ($contract->status === ContractStatusEnum::INACTIVE
                && $data['status'] === ContractStatusEnum::ACTIVE->value) {
                throw ValidationException::withMessages(['status' => 'Отозванный контракт не восстанавливается. Назначьте новый с подтверждением пользователя.']);
            }

            if (! $this->mayEditRights($contract) && array_key_exists('permissions', $data)
                && array_diff($data['permissions'], $before['permissions']) !== []) {
                throw ValidationException::withMessages(['permissions' => 'Права этого контракта назначаются в профильном разделе.']);
            }

            if ($this->mayEditRights($contract) && array_key_exists('permissions', $data)) {
                $allowed = array_keys($this->permissionOptions($contract));
                if ($data['permissions'] === [] || array_diff($data['permissions'], $allowed) !== []
                    || count($data['permissions']) === count($allowed)) {
                    throw ValidationException::withMessages(['permissions' => 'Укажите допустимый набор полномочий ниже уровня владельца.']);
                }
            }

            $expiresAt = $this->isOwnerContract($contract)
                ? $contract->expires_at : ($data['expires_at'] ?? null);
            if ($expiresAt !== null && $contract->starts_at !== null
                && \Carbon\CarbonImmutable::parse($expiresAt)->lessThanOrEqualTo($contract->starts_at)) {
                throw ValidationException::withMessages(['expires_at' => 'Окончание должно быть позже начала контракта.']);
            }

            $isPublicDelegation = $contract->comment === EntityContractManager::COMMENT_MARKER
                && $contract->membership !== null && ! $this->isOwnerContract($contract);
            $oldExpiry = $contract->expires_at;
            $newExpiry = $expiresAt === null ? null : \Carbon\CarbonImmutable::parse($expiresAt);
            $renewal = $isPublicDelegation && $oldExpiry !== null
                && ($newExpiry === null || $newExpiry->greaterThan($oldExpiry));

            $contract->update([
                'name' => trim($data['name']),
                'notes' => ($data['notes'] ?? '') === '' ? null : trim($data['notes']),
                'status' => $data['status'],
                'expires_at' => $expiresAt,
            ]);

            $newRights = [];
            if ($this->mayEditRights($contract) && array_key_exists('permissions', $data)
                && $data['status'] === ContractStatusEnum::ACTIVE->value) {
                $requested = array_values(array_unique($data['permissions']));
                $newRights = array_diff($requested, $before['permissions']);
                $contract->permissions()->delete();
                $contract->permissions()->createMany(array_map(
                    static fn (string $p): array => ['permission' => $p], $requested,
                ));
            }

            if ($data['status'] === ContractStatusEnum::ACTIVE->value
                && ($newRights !== [] || $renewal)
                && $contract->membership?->invitation_status === TeamInvitationStatusEnum::ACCEPTED) {
                // An extended term or widened rights must be accepted again.
                $contract->membership->update(['invitation_status' => TeamInvitationStatusEnum::PENDING]);
                $recipientId = (int) $contract->membership->user_id;
                DB::afterCommit(static function () use ($recipientId): void {
                    app(CreateUserNotificationHandler::class)->handle(new CreateUserNotificationDTO(
                        userId: $recipientId,
                        type: UserNotificationTypeEnum::REMINDER,
                        title: 'Изменены условия контракта',
                        body: 'Для новых условий требуется ваше подтверждение.',
                        actionUrl: route('account.contracts'),
                        actionText: 'Рассмотреть',
                    ));
                });
            }

            if ($data['status'] === ContractStatusEnum::INACTIVE->value && $contract->membership !== null
                && $contract->membership->invitation_status !== TeamInvitationStatusEnum::REVOKED) {
                $contract->membership->update(['invitation_status' => TeamInvitationStatusEnum::REVOKED]);
            }

            $after = [
                'name' => $contract->name,
                'notes' => $contract->notes,
                'status' => $contract->status->value,
                'expires_at' => $contract->expires_at?->toIso8601String(),
                'permissions' => $contract->permissions()->pluck('permission')->sort()->values()->all(),
            ];

            DB::table('contract_change_logs')->insert([
                'contract_id' => $contract->id,
                'changed_by_user_id' => auth('web')->user()?->canonical()->id,
                'action' => $data['status'] === ContractStatusEnum::INACTIVE->value
                    && $before['status'] === ContractStatusEnum::ACTIVE->value ? 'revoked' : 'updated',
                'reason' => trim($data['change_reason']),
                'before' => json_encode($before, JSON_UNESCAPED_UNICODE),
                'after' => json_encode($after, JSON_UNESCAPED_UNICODE),
                'created_at' => now(),
            ]);

            return $contract->refresh();
        });
    }
}
