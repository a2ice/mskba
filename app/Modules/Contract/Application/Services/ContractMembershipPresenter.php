<?php

namespace App\Modules\Contract\Application\Services;

use App\Modules\Contract\Application\DTO\ContractMembershipScopeDTO;
use App\Modules\Contract\Domain\Models\Contract;
use App\Modules\Contract\Domain\Models\ContractMembership;
use BackedEnum;
use Illuminate\Database\Eloquent\Model;

final class ContractMembershipPresenter
{
    public function accessLevelLabelFor(?ContractMembership $membership): ?string
    {
        if ($membership === null) {
            return null;
        }

        $enumClass = $membership->scope_type->accessLevelEnumClass();

        if ($enumClass !== null && is_a($enumClass, BackedEnum::class, true)) {
            $accessLevel = $enumClass::tryFrom($membership->access_level);

            if ($accessLevel !== null && method_exists($accessLevel, 'label')) {
                return $accessLevel->label();
            }
        }

        return $membership->access_level;
    }

    /**
     * @return array<array{type: string, type_label: string, id: int|string, name: string, url: ?string, permissions: string}>
     */
    public function scopesFor(Contract $contract): array
    {
        $membership = $contract->membership;

        if ($membership === null) {
            return [];
        }

        $modelClass = $membership->scope_type->modelClass();
        $entity = $modelClass === null
            ? null
            : $modelClass::query()->find($membership->scope_id);

        if ($modelClass !== null && $entity === null) {
            return [];
        }

        return [(new ContractMembershipScopeDTO(
            type: $membership->scope_type->value,
            typeLabel: $membership->scope_type->label(),
            id: $entity?->getKey() ?? $membership->scope_id,
            name: $this->nameForScope($membership, $entity),
            url: $this->urlForScope($membership->scope_type->showRouteName(), $entity),
            permissions: $contract->permissions
                ->pluck('permission')
                ->join(', '),
        ))->toArray()];
    }

    private function nameForScope(ContractMembership $membership, ?Model $entity): string
    {
        if ($entity === null) {
            return $membership->scope_type->label().' #'.$membership->scope_id;
        }

        return (string) data_get(
            $entity,
            $membership->scope_type->titleAttribute(),
            $membership->scope_type->label().' #'.$membership->scope_id,
        );
    }

    private function urlForScope(?string $routeName, ?Model $entity): ?string
    {
        if ($routeName === null || $entity === null) {
            return null;
        }

        return route($routeName, $entity);
    }
}
