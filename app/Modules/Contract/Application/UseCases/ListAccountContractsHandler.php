<?php

namespace App\Modules\Contract\Application\UseCases;

use App\Modules\Contract\Application\DTO\AccountContractListItemDTO;
use App\Modules\Contract\Application\Services\ContractMembershipPresenter;
use App\Modules\Contract\Domain\Models\Contract;
use App\Modules\Identity\Domain\Models\User;

final class ListAccountContractsHandler
{
    public function __construct(
        private readonly ContractMembershipPresenter $membershipPresenter,
    ) {}

    /**
     * @return array<AccountContractListItemDTO>
     */
    public function handle(User $user): array
    {
        $identityIds = $user->canonical()->identityIds();

        return Contract::query()
            ->whereHas('membership', fn ($query) => $query->whereIn('user_id', $identityIds))
            ->with(['membership', 'permissions'])
            ->orderBy('id')
            ->get()
            ->map(fn (Contract $contract) => new AccountContractListItemDTO(
                id: $contract->id,
                number: $contract->number,
                status: $contract->status->label(),
                accessLevel: $contract->membership?->access_level,
                accessLevelLabel: $this->membershipPresenter->accessLevelLabelFor($contract->membership),
                startsAt: $contract->starts_at?->format('d.m.Y H:i'),
                expiresAt: $contract->expires_at?->format('d.m.Y H:i'),
                scopes: $this->membershipPresenter->scopesFor($contract),
            ))
            ->all();
    }
}
