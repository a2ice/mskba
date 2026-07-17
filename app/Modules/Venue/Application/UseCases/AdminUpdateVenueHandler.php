<?php

namespace App\Modules\Venue\Application\UseCases;

use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Location\Application\DTO\CreateLocationDTO;
use App\Modules\Venue\Application\Services\VenueDetailsUpdater;
use App\Modules\Venue\Domain\Exceptions\VenueAccessDeniedException;
use App\Modules\Venue\Domain\Exceptions\VenueNotFoundException;
use App\Modules\Venue\Domain\Models\Venue;
use Illuminate\Support\Facades\DB;

final class AdminUpdateVenueHandler
{
    public function __construct(
        private readonly VenueDetailsUpdater $updater,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, string>  $tagNames
     */
    public function handle(?User $user, int $venueId, array $data, CreateLocationDTO $locationData, array $tagNames = []): Venue
    {
        if (! $user?->isConfirmed() || ! $user->hasSystemRole(UserSystemRoleEnum::SUPERADMIN)) {
            throw new VenueAccessDeniedException;
        }

        return DB::transaction(function () use ($venueId, $data, $locationData, $tagNames): Venue {
            $venue = Venue::query()->lockForUpdate()->find($venueId);

            if ($venue === null) {
                throw new VenueNotFoundException;
            }

            if ($venue->trashed()) {
                throw new VenueAccessDeniedException;
            }

            return $this->updater->update($venue, $data, $locationData, $tagNames);
        });
    }
}
