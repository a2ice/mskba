<?php

namespace App\Modules\Venue\Application\UseCases;

use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\Location\Application\DTO\CreateLocationDTO;
use App\Modules\Location\Application\UseCases\CreateLocationHandler;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Enums\VenueTypeEnum;
use App\Modules\Venue\Domain\Models\Venue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CreateAccountVenueHandler
{
    public function __construct(
        private readonly CreateLocationHandler $createLocation,
    ) {}

    /**
     * @param  array{name: string, type: string, description?: string|null, raw_address?: string|null}  $data
     */
    public function handle(?Actor $actor, array $data, ?CreateLocationDTO $locationData = null): Venue
    {
        return DB::transaction(function () use ($actor, $data, $locationData): Venue {
            $rawAddress = $locationData?->rawAddress ?? $data['raw_address'] ?? null;
            $location = $locationData === null
                ? null
                : $this->createLocation->handle($locationData);

            return Venue::query()->create([
                'created_by_actor_id' => $actor?->id,
                'location_id' => $location?->id,
                'name' => $data['name'],
                'alias' => $this->makeUniqueAlias($data['name']),
                'type' => VenueTypeEnum::from($data['type'])->value,
                'status' => VenueStatusEnum::UNCONFIRMED->value,
                'description' => $data['description'] ?? null,
                'raw_address' => $rawAddress,
            ]);
        });
    }

    private function makeUniqueAlias(string $name): string
    {
        $baseAlias = Str::slug($name);

        if ($baseAlias === '') {
            $baseAlias = 'venue';
        }

        $alias = $baseAlias;
        $counter = 2;

        while (Venue::query()->where('alias', $alias)->exists()) {
            $alias = "{$baseAlias}-{$counter}";
            $counter++;
        }

        return $alias;
    }
}
