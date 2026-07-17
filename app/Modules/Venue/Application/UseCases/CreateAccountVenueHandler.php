<?php

namespace App\Modules\Venue\Application\UseCases;

use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\Location\Application\DTO\CreateLocationDTO;
use App\Modules\Location\Application\UseCases\CreateLocationHandler;
use App\Modules\Venue\Application\Services\VenueUniquenessChecker;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Enums\VenueTypeEnum;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\Venue\Infrastructure\Jobs\FindVenueDuplicatesJob;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class CreateAccountVenueHandler
{
    public function __construct(
        private readonly CreateLocationHandler $createLocation,
        private readonly VenueUniquenessChecker $uniqueness,
    ) {}

    /**
     * @param  array{name: string, type: string, short_description?: string|null, full_description?: string|null, raw_address?: string|null}  $data
     */
    public function handle(?Actor $actor, array $data, ?CreateLocationDTO $locationData = null): Venue
    {
        $venue = DB::transaction(function () use ($actor, $data, $locationData): Venue {
            $rawAddress = $locationData?->rawAddress ?? $data['raw_address'] ?? null;
            $type = VenueTypeEnum::from($data['type']);

            if ($this->uniqueness->aliasExistsForName($data['name'], $type, [VenueStatusEnum::CONFIRMED])) {
                throw new InvalidArgumentException('Площадка с таким названием уже существует.');
            }

            if ($this->uniqueness->addressExists(
                rawAddress: $rawAddress,
                city: $locationData?->city,
                street: $locationData?->street,
                building: $locationData?->building,
                type: $type,
                statuses: [VenueStatusEnum::CONFIRMED],
            )) {
                throw new InvalidArgumentException('Площадка с таким адресом уже существует.');
            }

            if ($actor !== null && $this->uniqueness->aliasExistsForActor(
                $actor,
                $this->uniqueness->aliasForName($data['name']),
                $type,
                [VenueStatusEnum::UNCONFIRMED, VenueStatusEnum::BLOCKED],
            )) {
                throw new InvalidArgumentException('Вы уже добавили площадку с таким названием.');
            }

            if ($actor !== null && $this->uniqueness->addressExistsForActor(
                actor: $actor,
                rawAddress: $rawAddress,
                city: $locationData?->city,
                street: $locationData?->street,
                building: $locationData?->building,
                type: $type,
                statuses: [VenueStatusEnum::UNCONFIRMED, VenueStatusEnum::BLOCKED],
            )) {
                throw new InvalidArgumentException('Вы уже добавили площадку с таким адресом.');
            }

            $location = $locationData === null
                ? null
                : $this->createLocation->handle($locationData);

            return Venue::query()->create([
                'created_by_actor_id' => $actor?->id,
                'location_id' => $location?->id,
                'name' => $data['name'],
                'alias' => $this->uniqueness->aliasForName($data['name']),
                'type' => $type->value,
                'status' => VenueStatusEnum::UNCONFIRMED->value,
                'short_description' => $data['short_description'] ?? null,
                'full_description' => $data['full_description'] ?? null,
                'raw_address' => $rawAddress,
            ]);
        });

        FindVenueDuplicatesJob::dispatch($venue->id)->afterCommit();

        return $venue;
    }
}
