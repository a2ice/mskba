<?php

namespace App\Modules\Contract\Application\UseCases;

use App\Modules\Contract\Application\DTO\AccountContractDetailsDTO;
use App\Modules\Contract\Application\Services\ContractMembershipPresenter;
use App\Modules\Contract\Domain\Models\Contract;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;

final class ShowAccountContractHandler
{
    public function __construct(
        private readonly ContractMembershipPresenter $membershipPresenter,
    ) {}

    public function handle(string $number, User $user): AccountContractDetailsDTO
    {
        $contract = Contract::query()
            ->whereHas('membership', fn ($query) => $query->where('user_id', $user->id))
            ->where(function ($query) use ($number): void {
                $query->where('number', $number);

                if (ctype_digit($number)) {
                    $query->orWhere('id', (int) $number);
                }
            })
            ->with(['membership', 'permissions', 'assignedByUser'])
            ->first();

        if ($contract === null) {
            throw (new ModelNotFoundException)->setModel(Contract::class, [$number]);
        }

        return new AccountContractDetailsDTO(
            id: $contract->id,
            number: $contract->number,
            name: $contract->name,
            type: $contract->family->label(),
            status: $contract->status->label(),
            accessLevel: $contract->membership?->access_level,
            accessLevelLabel: $this->membershipPresenter->accessLevelLabelFor($contract->membership),
            startsAt: $contract->starts_at?->format('d.m.Y H:i'),
            expiresAt: $contract->expires_at?->format('d.m.Y H:i'),
            description: $contract->comment,
            assignedBy: $contract->assigned_by === null ? null : (string) $contract->assigned_by,
            assignedByUser: $contract->assignedByUser,
            permissions: $contract->permissions
                ->pluck('permission')
                ->unique()
                ->join(', '),
            scopes: $this->membershipPresenter->scopesFor($contract),
        );
    }
}
