<?php

namespace App\Modules\Venue\Application\UseCases;

use App\Modules\Audit\Domain\Models\AuditLog;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\VenueBooking\Domain\Models\VenueBooking;
use App\Modules\Contract\Domain\Models\ContractMembership;
use App\Modules\Contract\Domain\Enums\ContractMembershipScopeTypeEnum;
use App\Modules\VenueBooking\Domain\Models\VenueRentalPreRequest;
use App\Modules\Venue\Domain\Models\VenueOwnershipClaim;
use App\Modules\PlayInterest\Domain\Models\VenueSubscription;
use App\Modules\PlayInterest\Domain\Models\VenueGroupOffer;
use App\Modules\Venue\Domain\Enums\VenueDuplicateStatusEnum;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\Venue\Domain\Models\VenueDuplicate;
use App\Modules\Venue\Domain\Models\VenueOwnership;
use App\Modules\Venue\Domain\Models\VenueUserRestriction;
use App\Modules\Venue\Application\Services\VenueDuplicateContractRights;
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
        string $contractResolution = VenueDuplicateContractRights::KEEP,
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

        $this->handleBatch([$canonicalVenueId, $duplicateVenueId], $canonicalVenueId, $resolvedBy, $contractResolution);
    }

    /**
     * @param  array<int, int>  $venueIds
     */
    public function handleBatch(array $venueIds, int $canonicalVenueId, ?User $resolvedBy, string $contractResolution = VenueDuplicateContractRights::KEEP): void
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

        DB::transaction(function () use ($venueIds, $canonicalVenueId, $duplicateVenueIds, $resolvedBy, $contractResolution): void {
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

                // This is an identity link, not a destructive data migration.
                // Don't hide a record that owns transactions, events, contracts
                // or rental settings whose relationships would become orphaned
                // from the public-facing canonical venue.
                $this->assertSafeToLink($duplicate);
                $modifiedMemberships = app(VenueDuplicateContractRights::class)
                    ->resolve($canonical, $duplicate, $contractResolution, $resolvedBy);

                $duplicateOldValues = [
                    'canonical_venue_id' => $duplicate->getRawOriginal('canonical_venue_id'),
                    'is_published' => $duplicate->getRawOriginal('is_published'),
                ];

                $duplicate->forceFill([
                    'canonical_venue_id' => $canonical->id,
                    'is_published' => false,
                ])->save();

                $candidate = $this->candidateForDuplicate($candidates, $duplicate->id);

                $this->writeMergeAuditLog($canonical, $duplicate, $candidate, $duplicateOldValues, $contractResolution, $modifiedMemberships);
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

        if ($canonical->status !== VenueStatusEnum::CONFIRMED) {
            throw new InvalidArgumentException('В группе уже есть подтвержденная площадка, она должна остаться главной.');
        }
    }

    /**
     * A human-reviewed pair may contain two confirmed venues. Keep their
     * records intact, and disallow hiding a venue whose operational data
     * requires a deliberate separate migration.
     */
    private function assertSafeToLink(Venue $duplicate): void
    {
        $blockers = [];
        if (Event::query()->where('venue_id', $duplicate->id)->exists()) {
            $blockers[] = 'мероприятия';
        }
        if (VenueBooking::query()->where('venue_id', $duplicate->id)->exists()) {
            $blockers[] = 'бронирования';
        }
        if (VenueOwnership::query()->where('venue_id', $duplicate->id)->exists()) {
            $blockers[] = 'история подтверждённого владения';
        }
        if (VenueUserRestriction::query()->where('venue_id', $duplicate->id)
            ->where('active_marker', true)->exists()) {
            $blockers[] = 'действующие ограничения пользователей';
        }
        $courtIds = $duplicate->courts()->pluck('id');
        if ($courtIds->isNotEmpty() && ContractMembership::query()
            ->where('scope_type', ContractMembershipScopeTypeEnum::VENUE_COURT->value)
            ->whereIn('scope_id', $courtIds)
            ->whereHas('contract', fn ($q) => $q->where('status', 'active'))->exists()) {
            $blockers[] = 'договорные права на отдельные залы';
        }
        if (VenueRentalPreRequest::query()->where('venue_id', $duplicate->id)->exists()) {
            $blockers[] = 'предзаявки аренды';
        }
        if (VenueOwnershipClaim::query()->where('venue_id', $duplicate->id)->exists()) {
            $blockers[] = 'заявки на владение';
        }
        if (VenueSubscription::query()->where('venue_id', $duplicate->id)->exists()) {
            $blockers[] = 'подписки пользователей';
        }
        if (VenueGroupOffer::query()->where('venue_id', $duplicate->id)->exists()) {
            $blockers[] = 'специальные условия и предложения';
        }
        if ($duplicate->integrations()->exists()) {
            $blockers[] = 'интеграции расписания';
        }
        if ($duplicate->courts()->whereHas('schedule')->exists()) {
            $blockers[] = 'расписания залов';
        }

        if ($blockers !== []) {
            throw new InvalidArgumentException(
                'Нельзя скрыть дубль #'.$duplicate->id.': есть связанные '
                .implode(', ', $blockers)
                .'. Выбери основную площадку с этими данными либо сначала перенеси связи отдельной операцией.'
            );
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
        string $contractResolution,
        array $modifiedMemberships,
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
                'canonical_venue_id' => $canonical->id,
                'is_published' => false,
            ],
            'metadata' => [
                'canonical_venue_id' => $canonical->id,
                'duplicate_venue_id' => $duplicate->id,
                'venue_duplicate_id' => $candidate->id,
                'route' => $request?->route()?->getName(),
                'contract_resolution' => $contractResolution,
                'resolved_contract_membership_ids' => $modifiedMemberships,
            ],
        ]);
    }
}
