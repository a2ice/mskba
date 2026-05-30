<?php

namespace App\Modules\Venue\Application\UseCases;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Application\DTO\VenueDetailsDTO;
use App\Modules\Venue\Application\Services\VenueAccessResolver;
use App\Modules\Venue\Domain\Exceptions\VenueAccessDeniedException;
use App\Modules\Venue\Domain\Exceptions\VenueNotFoundException;
use App\Modules\Venue\Domain\Models\Venue;

final class ShowVenueHandler
{
    public function __construct(
        private readonly VenueAccessResolver $access,
    ) {}

    public function handle(string $alias, ?User $user): VenueDetailsDTO
    {
        $venue = Venue::query()
            ->where('alias', $alias)
            ->first();

        if ($venue === null) {
            throw new VenueNotFoundException;
        }
        if (! $this->access->canView($user, $venue)) {
            throw new VenueAccessDeniedException;
        }

        return new VenueDetailsDTO(
            id: $venue->id,
            name: $venue->name,
            alias: $venue->alias,
            type: $venue->type->label(),
            status: $venue->status->label(),
            description: $venue->description,
            canEdit: $this->access->canEdit($user, $venue),
            canEditSchedule: $this->access->canEditSchedule($user, $venue),
        );
    }
}
