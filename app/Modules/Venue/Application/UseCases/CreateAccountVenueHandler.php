<?php

namespace App\Modules\Venue\Application\UseCases;

use App\Modules\Contract\Application\Services\EntityContractManager;
use App\Modules\Contract\Domain\Enums\ContractMembershipScopeTypeEnum as Scope;
use App\Modules\Identity\Application\Services\UserOperationalPermissionChecker;
use App\Modules\Identity\Domain\Enums\UserOperationalPermissionEnum;
use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Location\Application\DTO\CreateLocationDTO;
use App\Modules\Location\Application\UseCases\CreateLocationHandler;
use App\Modules\Venue\Application\Services\VenueOwnershipClaimDraftManager;
use App\Modules\Venue\Application\Services\VenueProximityService;
use App\Modules\Venue\Application\Services\VenueTagSynchronizer;
use App\Modules\Venue\Application\Services\VenueCourtConfigurator;
use App\Modules\Venue\Application\Services\VenueFacilitiesSynchronizer;
use App\Modules\Venue\Application\Services\VenueUniquenessChecker;
use App\Modules\Venue\Domain\Enums\VenueCreationRoleEnum;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Enums\VenueTypeEnum;
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
        private readonly VenueCourtConfigurator $courtConfigurator,
        private readonly VenueFacilitiesSynchronizer $facilities,
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
        abort_unless(app(UserOperationalPermissionChecker::class)->allows(
            $actor->user, UserOperationalPermissionEnum::CREATE_VENUE,
        ), 403);

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

                $this->courtConfigurator->configure($venue, (array) ($data['initial_courts'] ?? []));
                $this->facilities->sync($venue, [], (array) ($data['amenity_ids'] ?? []));

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
