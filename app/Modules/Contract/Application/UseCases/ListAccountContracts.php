<?php

namespace App\Modules\Contract\Application\UseCases;

use App\Modules\Contract\Application\DTO\AccountContractListItemDTO;
use App\Modules\Contract\Domain\Models\Contract;
use App\Modules\Identity\Domain\Models\User;

final class ListAccountContracts
{
    /**
     * @return array<AccountContractListItemDTO>
     */
    public function handle(User $user): array
    {
        return Contract::query()
            ->where('user_id', $user->id)
            ->with(['venueContracts.venue', 'venueContracts.permissions'])
            ->orderBy('id')
            ->get()
            ->map(fn (Contract $contract) => new AccountContractListItemDTO(
                id: $contract->id,
                number: $contract->number,
                status: $contract->status->label(),
                startsAt: $contract->starts_at?->format('d.m.Y H:i'),
                expiresAt: $contract->expires_at?->format('d.m.Y H:i'),
                venues: $contract->venueContracts
                    ->map(fn ($venueContract) => [
                        'id' => $venueContract->venue->id,
                        'name' => $venueContract->venue->name,
                        'alias' => $venueContract->venue->alias,
                        'permissions' => $venueContract->permissions
                            ->pluck('permission')
                            ->join(', '),
                    ])
                    ->all(),
            ))
            ->all();
    }
}
