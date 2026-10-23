<?php

namespace App\Filament\Backoffice\Resources\Venues;

use App\Modules\Identity\Application\Services\BackofficePermissionManager;
use App\Modules\Identity\Domain\Enums\UserOperationalPermissionEnum as Permission;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Location\Application\DTO\CreateLocationDTO;
use App\Modules\Location\Application\UseCases\CreateLocationHandler;
use App\Modules\Venue\Application\Services\VenueFacilitiesSynchronizer;
use App\Modules\Venue\Application\Services\VenueTagSynchronizer;
use App\Modules\Venue\Application\Services\VenueUniquenessChecker;
use App\Modules\Venue\Application\Services\VenueProximityService;
use App\Modules\Venue\Application\UseCases\UpdateVenueStatusHandler;
use App\Modules\Venue\Application\UseCases\BackofficeVenueModerationHandler;
use App\Modules\Venue\Domain\Enums\VenueOperationalStatusEnum;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Enums\VenueTypeEnum;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\Venue\Infrastructure\Jobs\FindVenueDuplicatesJob;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class VenueBackofficeEditor
{
    public function __construct(
        private readonly CreateLocationHandler $locations,
        private readonly VenueFacilitiesSynchronizer $facilities,
        private readonly VenueTagSynchronizer $tags,
        private readonly VenueUniquenessChecker $uniqueness,
        private readonly VenueProximityService $proximity,
        private readonly UpdateVenueStatusHandler $statusTransitions,
        private readonly BackofficeVenueModerationHandler $moderation,
        private readonly CurrentActorResolver $actorResolver,
    ) {}

    /** @return array<string, mixed> */
    public function fields(Venue $venue): array
    {
        $venue->loadMissing('location.address', 'location.metroStations', 'amenities', 'tags',
            'characteristics', 'schedule.intervals', 'schedule.exceptions.intervals');

        $address = $venue->location?->address;
        $characteristics = $venue->characteristics;
        $schedule = $venue->schedule;

        return [
            'access_type' => $venue->requires_payment === null ? 'unknown' : ($venue->requires_payment ? 'paid' : 'free'),
            'tags_text' => $venue->tags->pluck('name')->implode(', '),
            'amenity_ids' => $venue->amenities->pluck('id')->all(),
            'characteristics' => [
                'hoops_count' => $characteristics?->hoops_count,
                'hoops_condition' => $characteristics?->hoops_condition,
                'surface_condition' => $characteristics?->surface_condition,
                'marking_condition' => $characteristics?->marking_condition?->value,
            ],
            'location_city' => $address?->city,
            'location_city_id' => $address?->city_id,
            'location_district_id' => $address?->district_id,
            'location_street' => $address?->street,
            'location_building' => $address?->building,
            'location_postal_code' => $address?->postal_code,
            'location_latitude' => $address?->latitude,
            'location_longitude' => $address?->longitude,
            'location_metro_ids' => $venue->location?->metroStations->pluck('id')->all() ?? [],
            'schedule_timezone' => $schedule?->timezone ?? 'Europe/Moscow',
            'weekly_intervals' => $schedule?->intervals->map(fn ($row): array => [
                'day_of_week' => $row->day_of_week,
                'starts_at' => substr((string) $row->starts_at, 0, 5),
                'ends_at' => substr((string) $row->ends_at, 0, 5),
            ])->values()->all() ?? [],
            'schedule_exceptions' => $schedule?->exceptions->map(fn ($row): array => [
                'date' => $row->date->format('Y-m-d'),
                'is_closed' => $row->is_closed,
                'intervals' => $row->intervals->map(fn ($time): array => [
                    'starts_at' => substr((string) $time->starts_at, 0, 5),
                    'ends_at' => substr((string) $time->ends_at, 0, 5),
                ])->values()->all(),
            ])->values()->all() ?? [],
        ];
    }

    /** @param array<string, mixed> $data */
    public function save(array $data, ?Venue $original = null): Venue
    {
        $actor = auth('web')->user();
        abort_unless($actor instanceof User
            && app(BackofficePermissionManager::class)->allows($actor, Permission::BACKOFFICE_ACCESS)
            && app(BackofficePermissionManager::class)->allows($actor, Permission::BACKOFFICE_VENUE_MANAGE), 403);

        $this->validateSchedule($data);
        $name = trim((string) ($data['name'] ?? ''));
        $alias = trim((string) ($data['alias'] ?? ''));
        if ($alias === '') {
            $alias = $original?->alias ?: $this->uniqueness->aliasForName($name);
        }

        return DB::transaction(function () use ($data, $original, $actor, $name, $alias): Venue {
            $venue = $original === null ? null : Venue::query()->lockForUpdate()->findOrFail($original->id);
            abort_if($venue?->trashed(), 404);

            if ($original === null) {
                $baseAlias = $alias;
                $suffix = 2;
                while (Venue::withTrashed()->where('alias', $alias)->exists()) {
                    $alias = $baseAlias.'-'.$suffix++;
                }
            }

            $rawAddress = trim((string) ($data['raw_address'] ?? ''));
            if ($rawAddress === '') {
                $rawAddress = implode(', ', array_filter([
                    trim((string) ($data['location_city'] ?? '')),
                    trim((string) ($data['location_street'] ?? '')),
                    trim((string) ($data['location_building'] ?? '')),
                ], static fn (string $part): bool => $part !== ''));
            }

            $location = $this->resolveLocation($venue, $data, $rawAddress);
            $status = VenueStatusEnum::from((string) ($data['status'] ?? VenueStatusEnum::UNCONFIRMED->value));
            $autoApprove = $status === VenueStatusEnum::CONFIRMED
                && ($venue === null || $venue->status !== VenueStatusEnum::CONFIRMED);
            if ($autoApprove && ! $this->moderation->canApproveImmediately($actor)) {
                throw ValidationException::withMessages([
                    'data.status' => 'Быстрое подтверждение доступно только superadmin.',
                ]);
            }

            if ($autoApprove && ($location?->address?->latitude === null
                || $location?->address?->longitude === null)) {
                throw ValidationException::withMessages([
                    'data.location_latitude' => 'Для подтверждения площадки необходимо указать широту.',
                    'data.location_longitude' => 'Для подтверждения площадки необходимо указать долготу.',
                ]);
            }

            if ($location?->address?->latitude !== null && $location?->address?->longitude !== null
                && $this->proximity->existsNearCoordinates(
                    type: VenueTypeEnum::from((string) $data['type']),
                    latitude: (float) $location->address->latitude,
                    longitude: (float) $location->address->longitude,
                    radiusMeters: $this->proximity->strongRadiusMeters(),
                    statuses: [VenueStatusEnum::CONFIRMED],
                    exceptVenueId: $venue?->id,
                )) {
                throw ValidationException::withMessages([
                    'data.raw_address' => 'Рядом уже существует подтверждённая площадка такого типа.',
                ]);
            }

            $attributes = [
                'name' => $name,
                'alias' => $alias,
                'type' => VenueTypeEnum::from((string) $data['type'])->value,
                'operational_status' => VenueOperationalStatusEnum::from(
                    (string) ($data['operational_status'] ?? VenueOperationalStatusEnum::ACTIVE->value)
                )->value,
                'requires_payment' => match ($data['access_type'] ?? 'unknown') {
                    'paid' => true, 'free' => false, default => null,
                },
                'requires_booking_approval' => (bool) ($data['requires_booking_approval'] ?? false),
                'short_description' => $data['short_description'] ?? null,
                'full_description' => $data['full_description'] ?? null,
                'raw_address' => $rawAddress !== '' ? $rawAddress : null,
                'location_id' => $location?->id,
            ];

            if ($venue === null) {
                $attributes['status'] = VenueStatusEnum::UNCONFIRMED->value;
                $attributes['created_by_actor_id'] = $this->actorResolver->resolve($actor, null)?->id;
                $venue = Venue::query()->create($attributes);
            } else {
                $venue->update($attributes);
            }

            $tagNames = preg_split('/[,;\n]+/u', (string) ($data['tags_text'] ?? '')) ?: [];
            $this->tags->sync($venue, array_map('trim', $tagNames));
            $this->facilities->sync($venue,
                is_array($data['characteristics'] ?? null) ? $data['characteristics'] : [],
                is_array($data['amenity_ids'] ?? null) ? $data['amenity_ids'] : [],
            );
            $this->saveSchedule($venue, $data);
            if ($venue->status !== $status && ! $autoApprove) {
                $venue = $this->statusTransitions->handle(
                    $venue, $status, isset($data['status_info']) ? (string) $data['status_info'] : null,
                );
            }
            $venue->increment('content_version');

            if ($autoApprove) {
                // Submit and approve an actual moderation request under the same
                // transaction; a failed review rolls back the entire edit.
                try {
                    $this->moderation->approveImmediately($venue, $actor);
                } catch (\InvalidArgumentException $exception) {
                    // A domain rule must be displayed as a form error instead
                    // of an unhandled Livewire page-loading exception.
                    throw ValidationException::withMessages([
                        'data.status' => $exception->getMessage(),
                    ]);
                }
            }

            if ($original === null) {
                FindVenueDuplicatesJob::dispatch($venue->id)->afterCommit();
            }

            return $venue->refresh();
        });
    }

    /** @param array<string, mixed> $data */
    private function resolveLocation(?Venue $venue, array $data, string $rawAddress): ?\App\Modules\Location\Domain\Models\Location
    {
        $venue?->loadMissing('location.address', 'location.metroStations');
        $old = $venue?->location;
        $address = $old?->address;
        $metro = collect($data['location_metro_ids'] ?? [])->map('intval')->sort()->values()->all();
        $beforeMetro = $old?->metroStations->pluck('id')->map('intval')->sort()->values()->all() ?? [];
        $same = $venue !== null
            && trim((string) ($address?->full_address ?? '')) === $rawAddress
            && trim((string) ($address?->city ?? '')) === trim((string) ($data['location_city'] ?? ''))
            && (int) ($address?->city_id ?? 0) === (int) ($data['location_city_id'] ?? 0)
            && (int) ($address?->district_id ?? 0) === (int) ($data['location_district_id'] ?? 0)
            && trim((string) ($address?->street ?? '')) === trim((string) ($data['location_street'] ?? ''))
            && trim((string) ($address?->building ?? '')) === trim((string) ($data['location_building'] ?? ''))
            && trim((string) ($address?->postal_code ?? '')) === trim((string) ($data['location_postal_code'] ?? ''))
            && round((float) ($address?->latitude ?? 0), 7) === round((float) ($data['location_latitude'] ?? 0), 7)
            && round((float) ($address?->longitude ?? 0), 7) === round((float) ($data['location_longitude'] ?? 0), 7)
            && $beforeMetro === $metro;
        if ($same) {
            return $old;
        }

        $asString = static fn (string $key): ?string => trim((string) ($data[$key] ?? '')) ?: null;
        $asFloat = static fn (string $key): ?float =>
            isset($data[$key]) && $data[$key] !== '' ? (float) $data[$key] : null;

        return $this->locations->handle(new CreateLocationDTO(
            rawAddress: $rawAddress !== '' ? $rawAddress : null,
            cityId: isset($data['location_city_id']) && $data['location_city_id'] !== ''
                ? (int) $data['location_city_id'] : null,
            districtId: isset($data['location_district_id']) && $data['location_district_id'] !== ''
                ? (int) $data['location_district_id'] : null,
            city: $asString('location_city'),
            street: $asString('location_street'),
            building: $asString('location_building'),
            postalCode: $asString('location_postal_code'),
            latitude: $asFloat('location_latitude'),
            longitude: $asFloat('location_longitude'),
            metroStationIds: $metro,
        ));
    }

    /** @param array<string, mixed> $data */
    private function saveSchedule(Venue $venue, array $data): void
    {
        $weekly = array_values((array) ($data['weekly_intervals'] ?? []));
        $exceptions = array_values((array) ($data['schedule_exceptions'] ?? []));
        $timezone = trim((string) ($data['schedule_timezone'] ?? 'Europe/Moscow'));

        if ($weekly === [] && $exceptions === [] && $venue->schedule === null) {
            return;
        }

        $schedule = $venue->schedule()->firstOrCreate([], ['timezone' => $timezone]);
        $schedule->update(['timezone' => $timezone]);
        $schedule->intervals()->delete();
        foreach ($weekly as $index => $interval) {
            $schedule->intervals()->create([
                'day_of_week' => (int) $interval['day_of_week'],
                'starts_at' => $this->time($interval['starts_at']),
                'ends_at' => $this->time($interval['ends_at']),
                'sort_order' => $index,
            ]);
        }

        // Only schedule-owned rows are replaced; confirmed bookings remain untouched.
        $schedule->exceptions()->delete();
        foreach ($exceptions as $exceptionData) {
            $exception = $schedule->exceptions()->create([
                'date' => $exceptionData['date'],
                'is_closed' => (bool) ($exceptionData['is_closed'] ?? false),
            ]);
            if (! $exception->is_closed) {
                foreach (array_values((array) ($exceptionData['intervals'] ?? [])) as $index => $interval) {
                    $exception->intervals()->create([
                        'starts_at' => $this->time($interval['starts_at']),
                        'ends_at' => $this->time($interval['ends_at']),
                        'sort_order' => $index,
                    ]);
                }
            }
        }
    }

    /** @param array<string, mixed> $data */
    private function validateSchedule(array $data): void
    {
        $timezone = trim((string) ($data['schedule_timezone'] ?? 'Europe/Moscow'));
        try {
            new DateTimeZone($timezone);
        } catch (\Throwable) {
            throw ValidationException::withMessages(['data.schedule_timezone' => 'Укажи корректный часовой пояс.']);
        }
        $days = [];
        foreach ((array) ($data['weekly_intervals'] ?? []) as $row) {
            $day = (int) ($row['day_of_week'] ?? 0);
            if ($day < 1 || $day > 7) {
                throw ValidationException::withMessages(['data.weekly_intervals' => 'День недели должен быть от 1 до 7.']);
            }
            $days[$day][] = $this->range($row);
        }
        foreach ($days as $ranges) {
            $this->assertNonOverlapping($ranges, 'data.weekly_intervals');
        }
        $dates = [];
        foreach ((array) ($data['schedule_exceptions'] ?? []) as $exception) {
            $date = (string) ($exception['date'] ?? '');
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || isset($dates[$date])) {
                throw ValidationException::withMessages(['data.schedule_exceptions' => 'Укажи корректные неповторяющиеся даты.']);
            }
            $dates[$date] = true;
            if (! (bool) ($exception['is_closed'] ?? false)) {
                $ranges = array_map(fn ($row): array => $this->range($row),
                    (array) ($exception['intervals'] ?? []));
                $this->assertNonOverlapping($ranges, 'data.schedule_exceptions');
            }
        }
    }

    private function time(mixed $value): string
    {
        $time = substr((string) $value, 0, 5);
        if (! preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time)) {
            throw ValidationException::withMessages(['data.weekly_intervals' => 'Неверный формат времени (ЧЧ:ММ).']);
        }

        return $time;
    }

    /** @param array<string, mixed> $row @return array{0:string,1:string} */
    private function range(array $row): array
    {
        $start = $this->time($row['starts_at'] ?? '');
        $end = $this->time($row['ends_at'] ?? '');
        if ($start >= $end) {
            throw ValidationException::withMessages(['data.weekly_intervals' => 'Конец интервала должен быть позже начала.']);
        }

        return [$start, $end];
    }

    /** @param array<int, array{0:string,1:string}> $ranges */
    private function assertNonOverlapping(array $ranges, string $field): void
    {
        usort($ranges, static fn (array $a, array $b): int => strcmp($a[0], $b[0]));
        for ($i = 1; $i < count($ranges); $i++) {
            if ($ranges[$i][0] < $ranges[$i - 1][1]) {
                throw ValidationException::withMessages([$field => 'Интервалы работы не должны пересекаться.']);
            }
        }
    }
}
