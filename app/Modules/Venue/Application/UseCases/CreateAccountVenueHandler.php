<?php

namespace App\Modules\Venue\Application\UseCases;

use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\Location\Application\DTO\CreateLocationDTO;
use App\Modules\Location\Application\UseCases\CreateLocationHandler;
use App\Modules\Venue\Application\Services\VenueProximityService;
use App\Modules\Venue\Application\Services\VenueTagSynchronizer;
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
        private readonly VenueProximityService $proximity,
        private readonly VenueTagSynchronizer $tagSynchronizer,
    ) {}

    /**
     * @param  array{name: string, type: string, short_description?: string|null, full_description?: string|null, raw_address?: string|null}  $data
     */
    public function handle(?Actor $actor, array $data, ?CreateLocationDTO $locationData = null, array $tagNames = []): Venue
    {
        if ($actor?->user_id === null) {
            throw new InvalidArgumentException('Войдите в аккаунт, чтобы добавить площадку.');
        }

        $venue = DB::transaction(function () use ($actor, $data, $locationData, $tagNames): Venue {
            $rawAddress = $locationData?->rawAddress ?? $data['raw_address'] ?? null;
            $type = VenueTypeEnum::from($data['type']);

            if ($locationData?->latitude === null || $locationData->longitude === null) {
                throw new InvalidArgumentException('Выберите адрес из подсказки, чтобы сохранить координаты площадки.');
            }

            if ($this->proximity->existsNearCoordinates(
                type: $type,
                latitude: $locationData->latitude,
                longitude: $locationData->longitude,
                radiusMeters: $this->proximity->strongRadiusMeters(),
                statuses: [VenueStatusEnum::CONFIRMED],
            )) {
                throw new InvalidArgumentException('Рядом уже существует подтвержденная площадка такого типа.');
            }

            if ($actor !== null && $this->proximity->existsNearCoordinates(
                type: $type,
                latitude: $locationData->latitude,
                longitude: $locationData->longitude,
                radiusMeters: $this->proximity->strongRadiusMeters(),
                statuses: [VenueStatusEnum::UNCONFIRMED, VenueStatusEnum::BLOCKED],
                actor: $actor,
            )) {
                throw new InvalidArgumentException('Вы уже добавили площадку такого типа рядом с этой точкой.');
            }

            $location = $locationData === null
                ? null
                : $this->createLocation->handle($locationData);

            $venue = Venue::query()->create([
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

            $this->tagSynchronizer->sync($venue, $tagNames);

            return $venue;
        });

        FindVenueDuplicatesJob::dispatch($venue->id)->afterCommit();

        return $venue;
    }
}
