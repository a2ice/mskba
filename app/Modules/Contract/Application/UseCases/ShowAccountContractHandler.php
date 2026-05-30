<?php

namespace App\Modules\Contract\Application\UseCases;

use App\Modules\Contract\Application\DTO\AccountContractDetailsDTO;
use App\Modules\Contract\Domain\Enums\ContractPartyTypeEnum;
use App\Modules\Contract\Domain\Enums\ContractTypesEnum;
use App\Modules\Contract\Domain\Models\Contract;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;

final class ShowAccountContractHandler
{
    public function handle(string $number, User $user): AccountContractDetailsDTO
    {
        $contract = Contract::query()
            ->whereHas('parties', function (Builder $query) use ($user): void {
                $query
                    ->where('party_type', ContractPartyTypeEnum::USER->value)
                    ->where('party_id', $user->id);
            })
            ->where(function ($query) use ($number): void {
                $query->where('number', $number);

                if (ctype_digit($number)) {
                    $query->orWhere('id', (int) $number);
                }
            })
            ->with(['venueContracts.venue', 'venueContracts.permissions'])
            ->first();

        if ($contract === null) {
            throw (new ModelNotFoundException)->setModel(Contract::class, [$number]);
        }

        return new AccountContractDetailsDTO(
            id: $contract->id,
            number: $contract->number,
            name: $contract->name,
            type: ContractTypesEnum::VENUE->label(),
            status: $contract->status->label(),
            startsAt: $contract->starts_at?->format('d.m.Y H:i'),
            expiresAt: $contract->expires_at?->format('d.m.Y H:i'),
            description: $contract->comment,
            assignedBy: $contract->assignedBy,
            assignedByUser: $contract->assignedByUser,
            permissions: $contract->venueContracts
                ->flatMap(fn ($venueContract) => $venueContract->permissions)
                ->pluck('permission')
                ->unique()
                ->join(', '),
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
        );
    }
}
