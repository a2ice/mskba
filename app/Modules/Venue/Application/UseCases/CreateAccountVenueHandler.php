<?php

namespace App\Modules\Venue\Application\UseCases;

use App\Modules\Contract\Application\Services\EntityContractManager;
use App\Modules\Contract\Domain\Enums\ContractMembershipScopeTypeEnum as Scope;
use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Location\Application\DTO\CreateLocationDTO;
use App\Modules\Location\Application\UseCases\CreateLocationHandler;
use App\Modules\Venue\Application\Services\VenueOwnershipClaimDraftManager;
use App\Modules\Venue\Application\Services\VenueProximityService;
use App\Modules\Venue\Application\Services\VenueTagSynchronizer;
use App\Modules\Venue\Application\Services\VenueUniquenessChecker;
use App\Modules\Venue\Domain\Enums\VenueCreationRoleEnum;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Enums\VenueTypeEnum;
use App\Modules\Venue\Domain\Enums\VenueCourtEnvironmentEnum;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\Venue\Infrastructure\Jobs\FindVenueDuplicatesJob;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

final class CreateAccountVenueHandler
{
    public function __construct(
        private readonly CreateLocationHandler $createLocation,
        private readonly VenueUniquenessChecker $uniqueness,
        private readonly VenueProximityService $proximity,
        private readonly VenueTagSynchronizer $tagSynchronizer,
        private readonly VenueOwnershipClaimDraftManager $drafts,
    ) {}

    /**
     * @param  array{name: string, type: string, short_description?: string|null, full_description?: string|null, raw_address?: string|null}  $data
     */
    public function handle(?Actor $actor, array $data, ?CreateLocationDTO $locationData = null, array $tagNames = []): Venue
    {
        if ($actor?->user_id === null) {
            throw new InvalidArgumentException('Войдите в аккаунт, чтобы добавить площадку.');
        }

        $draft = null;
        try {
            $venue = DB::transaction(function () use ($actor, $data, $locationData, $tagNames, &$draft): Venue {
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
                    'requires_payment' => match ($data['access_type'] ?? 'unknown') {
                        'free' => false,
                        'paid' => true,
                        default => null,
                    },
                    'requires_booking_approval' => (bool) ($data['requires_booking_approval'] ?? false),
                    'status' => VenueStatusEnum::UNCONFIRMED->value,
                    'short_description' => $data['short_description'] ?? null,
                    'full_description' => $data['full_description'] ?? null,
                    'raw_address' => $rawAddress,
                ]);

                // A contributor contract covers catalogue management; verified
                // commercial ownership still requires the existing claim workflow.
                $creator = User::query()->findOrFail($actor->user_id)->canonical();
                app(EntityContractManager::class)->grantOwner(
                    Scope::VENUE, $venue->id, $creator, $creator,
                    'Ответственный за площадку «'.$venue->name.'»',
                );
                $this->tagSynchronizer->sync($venue, $tagNames);

                // Only the new shared create form requests initial zones. Legacy
                // callers without court_count keep the previous creation contract.
                if (isset($data['court_count'])) {
                    $count = (int) $data['court_count'];
                    $hoops = (int) ($data['hoops_count'] ?? 2);
                    $outdoor = $type === VenueTypeEnum::STREET_COURT;
                    foreach (range(1, $count) as $index) {
                        $venue->courts()->create([
                            'name' => ($outdoor ? 'Зона ' : 'Зал ').$index,
                            'alias' => ($outdoor ? 'zona-' : 'zal-').$index,
                            'sort_order' => $index * 10,
                            'is_primary' => $index === 1,
                            'supports_halves' => false,
                            'hoops_count' => $hoops,
                            'surface_type' => $data['surface_type'] ?? null,
                            'environment' => $outdoor
                                ? VenueCourtEnvironmentEnum::OUTDOOR->value
                                : VenueCourtEnvironmentEnum::INDOOR->value,
                        ]);
                    }
                }

                if (($data['creation_role'] ?? null) === VenueCreationRoleEnum::REPRESENTATIVE->value) {
                    $draft = $this->drafts->create($venue, User::query()->findOrFail($actor->user_id),
                        (string) ($data['ownership_evidence'] ?? ''), $data['ownership_documents'] ?? []);
                }

                return $venue;
            });
        } catch (Throwable $exception) {
            if ($draft !== null) {
                $this->drafts->discardFiles($draft);
            }
            throw $exception;
        }

        FindVenueDuplicatesJob::dispatch($venue->id)->afterCommit();

        return $venue;
    }
}
