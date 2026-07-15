<?php

namespace App\Modules\Venue\Application\UseCases;

use App\Modules\Audit\Domain\Models\AuditLog;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Domain\Enums\VenueDuplicateStatusEnum;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\Venue\Domain\Models\VenueDuplicate;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

final class MergeVenueDuplicateHandler
{
    public function handle(
        VenueDuplicate $candidate,
        int $canonicalVenueId,
        int $duplicateVenueId,
        ?User $resolvedBy,
    ): void {
        if ($canonicalVenueId === $duplicateVenueId) {
            throw new InvalidArgumentException('Каноническая площадка и дубль должны отличаться.');
        }

        $candidateVenueIds = [(int) $candidate->venue_id, (int) $candidate->duplicate_venue_id];
        sort($candidateVenueIds);

        $requestedVenueIds = [$canonicalVenueId, $duplicateVenueId];
        sort($requestedVenueIds);

        if ($candidateVenueIds !== $requestedVenueIds) {
            throw new InvalidArgumentException('Выбранные площадки не относятся к этому кандидату дубля.');
        }

        $this->handleBatch([$canonicalVenueId, $duplicateVenueId], $canonicalVenueId, $resolvedBy);
    }

    /**
     * @param  array<int, int>  $venueIds
     */
    public function handleBatch(array $venueIds, int $canonicalVenueId, ?User $resolvedBy): void
    {
        $venueIds = collect($venueIds)
            ->map(fn (mixed $venueId): int => (int) $venueId)
            ->filter(fn (int $venueId): bool => $venueId > 0)
            ->unique()
            ->values();

        if ($venueIds->count() < 2) {
            throw new InvalidArgumentException('Для объединения нужно выбрать минимум две площадки.');
        }

        if (! $venueIds->contains($canonicalVenueId)) {
            throw new InvalidArgumentException('Каноническая площадка должна входить в выбранную группу.');
        }

        $duplicateVenueIds = $venueIds
            ->reject(fn (int $venueId): bool => $venueId === $canonicalVenueId)
            ->values();

        DB::transaction(function () use ($venueIds, $canonicalVenueId, $duplicateVenueIds, $resolvedBy): void {
            $venues = Venue::query()
                ->whereIn('id', $venueIds->all())
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            if ($venues->count() !== $venueIds->count()) {
                throw new InvalidArgumentException('Одна из выбранных площадок не найдена.');
            }

            /** @var Venue $canonical */
            $canonical = $venues->get($canonicalVenueId);

            $this->assertConfirmedCanonicalRule($venues, $canonical);

            $candidates = $this->candidatesForSelectedVenues($venueIds);

            if (! $this->isConnectedDuplicateGroup($venueIds, $candidates)) {
                throw new InvalidArgumentException('Можно объединять только дубли одной площадки.');
            }

            $canonical->forceFill([
                'status' => $canonical->status === VenueStatusEnum::DUPLICATE
                    ? VenueStatusEnum::UNCONFIRMED
                    : $canonical->status,
                'canonical_venue_id' => null,
            ])->save();

            $candidates->each(function (VenueDuplicate $candidate) use ($resolvedBy): void {
                $candidate->forceFill([
                    'status' => VenueDuplicateStatusEnum::MERGED,
                    'resolved_by' => $resolvedBy?->id,
                    'resolved_at' => now(),
                ])->save();
            });

            foreach ($duplicateVenueIds as $duplicateVenueId) {
                /** @var Venue $duplicate */
                $duplicate = $venues->get($duplicateVenueId);

                if ($duplicate->id === $canonical->canonical_venue_id) {
                    throw new InvalidArgumentException('Нельзя создать циклическую связь дублей.');
                }

                $duplicateOldValues = [
                    'status' => $duplicate->getRawOriginal('status'),
                    'canonical_venue_id' => $duplicate->getRawOriginal('canonical_venue_id'),
                ];

                $duplicate->forceFill([
                    'status' => VenueStatusEnum::DUPLICATE,
                    'canonical_venue_id' => $canonical->id,
                ])->save();

                $candidate = $this->candidateForDuplicate($candidates, $duplicate->id);

                $this->writeMergeAuditLog($canonical, $duplicate, $candidate, $duplicateOldValues);
            }
        });
    }

    /**
     * @param  Collection<int, Venue>  $venues
     */
    private function assertConfirmedCanonicalRule(Collection $venues, Venue $canonical): void
    {
        $confirmedVenues = $venues
            ->filter(fn (Venue $venue): bool => $venue->status === VenueStatusEnum::CONFIRMED)
            ->values();

        if ($confirmedVenues->isEmpty()) {
            return;
        }

        if ($confirmedVenues->count() > 1 || $canonical->status !== VenueStatusEnum::CONFIRMED) {
            throw new InvalidArgumentException('В группе уже есть подтвержденная площадка, она должна остаться главной.');
        }
    }

    /**
     * @param  Collection<int, int>  $venueIds
     * @return Collection<int, VenueDuplicate>
     */
    private function candidatesForSelectedVenues(Collection $venueIds): Collection
    {
        return VenueDuplicate::query()
            ->whereIn('status', [
                VenueDuplicateStatusEnum::PENDING->value,
                VenueDuplicateStatusEnum::MERGED->value,
            ])
            ->whereIn('venue_id', $venueIds->all())
            ->whereIn('duplicate_venue_id', $venueIds->all())
            ->get()
            ->values();
    }

    /**
     * @param  Collection<int, int>  $venueIds
     * @param  Collection<int, VenueDuplicate>  $candidates
     */
    private function isConnectedDuplicateGroup(Collection $venueIds, Collection $candidates): bool
    {
        if ($candidates->isEmpty()) {
            return false;
        }

        $adjacency = [];
        foreach ($candidates as $candidate) {
            $adjacency[(int) $candidate->venue_id][] = (int) $candidate->duplicate_venue_id;
            $adjacency[(int) $candidate->duplicate_venue_id][] = (int) $candidate->venue_id;
        }

        $visited = [];
        $stack = [(int) $venueIds->first()];

        while ($stack !== []) {
            $venueId = array_pop($stack);
            if (isset($visited[$venueId])) {
                continue;
            }

            $visited[$venueId] = true;
            foreach ($adjacency[$venueId] ?? [] as $nextVenueId) {
                if (! isset($visited[$nextVenueId])) {
                    $stack[] = $nextVenueId;
                }
            }
        }

        return $venueIds
            ->every(fn (int $venueId): bool => isset($visited[$venueId]));
    }

    /**
     * @param  Collection<int, VenueDuplicate>  $candidates
     */
    private function candidateForDuplicate(Collection $candidates, int $duplicateVenueId): VenueDuplicate
    {
        /** @var VenueDuplicate|null $candidate */
        $candidate = $candidates->first(fn (VenueDuplicate $candidate): bool => (int) $candidate->venue_id === $duplicateVenueId
            || (int) $candidate->duplicate_venue_id === $duplicateVenueId);

        if ($candidate === null) {
            throw new InvalidArgumentException('Кандидат дубля не найден.');
        }

        return $candidate;
    }

    /**
     * @param  array<string, mixed>  $duplicateOldValues
     */
    private function writeMergeAuditLog(
        Venue $canonical,
        Venue $duplicate,
        VenueDuplicate $candidate,
        array $duplicateOldValues,
    ): void {
        if (! (bool) config('audit.enabled', true) || ! Schema::hasTable('audit_logs')) {
            return;
        }

        $request = app()->bound('request') ? request() : null;

        AuditLog::query()->create([
            'actor_id' => $request === null || ! Schema::hasTable('actors')
                ? null
                : app(CurrentActorResolver::class)->resolveForRequest($request)?->id,
            'auditable_type' => Venue::class,
            'auditable_id' => $duplicate->id,
            'event' => 'merged',
            'old_values' => $duplicateOldValues,
            'new_values' => [
                'status' => VenueStatusEnum::DUPLICATE->value,
                'canonical_venue_id' => $canonical->id,
            ],
            'metadata' => [
                'canonical_venue_id' => $canonical->id,
                'duplicate_venue_id' => $duplicate->id,
                'venue_duplicate_id' => $candidate->id,
                'route' => $request?->route()?->getName(),
            ],
        ]);
    }
}
