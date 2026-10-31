<?php

namespace App\Modules\Contract\Application\Services;

use App\Modules\Contract\Domain\Enums\ContractFamilyEnum;
use App\Modules\Contract\Domain\Enums\ContractMembershipScopeTypeEnum;
use App\Modules\Contract\Domain\Enums\ContractStatusEnum;
use App\Modules\Contract\Domain\Enums\VenueMembershipAccessLevelEnum;
use App\Modules\Contract\Domain\Models\Contract;
use App\Modules\Contract\Domain\Models\ContractMembership;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleAssignerEnum;
use App\Modules\PlayInterest\Application\Services\VenueGroupOffers;
use App\Modules\Venue\Application\Services\VenueAccessResolver;
use App\Modules\Venue\Application\UseCases\ListAccountVenuesHandler;
use App\Modules\Venue\Domain\Enums\VenuePermissionEnum;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\Venue\Domain\Models\VenueCourt;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

final readonly class AccountContractsWorkspace
{
    public function __construct(
        private ListAccountVenuesHandler $accountVenues,
        private VenueAccessResolver $venueAccess,
        private VenueGroupOffers $offers,
        private ContractMembershipPresenter $presenter,
    ) {}

    /** Only membership contracts belonging to the canonical user's identities. */
    public function contracts(User $user, array $filters): LengthAwarePaginator
    {
        $ids = $user->canonical()->identityIds();
        $query = Contract::query()
            ->where('family', ContractFamilyEnum::MEMBERSHIP->value)
            ->whereHas('membership', function ($query) use ($ids, $filters): void {
                $query->whereIn('user_id', $ids);
                if (! empty($filters['type'])) {
                    $query->where('scope_type', $filters['type']);
                }
            })
            ->with(['membership', 'permissions', 'assignedByUser'])
            ->orderByDesc('id');

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        $page = $query->paginate(12)->withQueryString();
        $page->getCollection()->transform(function (Contract $contract) use ($user): array {
            return [
                'contract' => $contract,
                'scopes' => $this->presenter->scopesFor($contract),
                'access_label' => $this->presenter->accessLevelLabelFor($contract->membership),
                'can_manage' => ($contract->membership?->scope_type !== null)
                    && (function () use ($contract, $user): bool {
                        $manager = app(EntityContractManager::class);
                        $permission = $manager->managePermission($contract->membership->scope_type);
                        return $permission !== null && $manager->allows($user,
                            $contract->membership->scope_type, (int) $contract->membership->scope_id, $permission);
                    })(),
            ];
        });

        return $page;
    }

    /**
     * Returns venue cards only for actual owned/bootstrap venues and contracts
     * belonging to the viewer, including historical (inactive) contracts.
     * Court-scoped membership is never misrepresented as a venue-level grant.
     */
    public function venues(User $user, array $filters): LengthAwarePaginator
    {
        $canonical = $user->canonical();
        $memberships = ContractMembership::query()
            ->whereIn('user_id', $canonical->identityIds())
            ->whereIn('scope_type', [
                ContractMembershipScopeTypeEnum::VENUE->value,
                ContractMembershipScopeTypeEnum::VENUE_COURT->value,
            ])
            ->with(['contract.permissions', 'contract.assignedByUser'])
            ->get()
            ->filter(fn (ContractMembership $m): bool =>
                $m->contract?->family === ContractFamilyEnum::MEMBERSHIP);

        $courtIds = $memberships
            ->filter(fn (ContractMembership $m): bool =>
                $m->scope_type === ContractMembershipScopeTypeEnum::VENUE_COURT)
            ->pluck('scope_id')->all();
        $courtVenues = VenueCourt::query()->whereIn('id', $courtIds)
            ->pluck('venue_id', 'id');

        $byVenue = [];
        foreach ($memberships as $membership) {
            $venueId = $membership->scope_type === ContractMembershipScopeTypeEnum::VENUE
                ? (int) $membership->scope_id
                : (int) ($courtVenues[$membership->scope_id] ?? 0);
            if ($venueId > 0) {
                $byVenue[$venueId][] = $membership;
            }
        }

        $bootstrap = $this->venueAccess->bootstrapOwnedVenueIdsFor($canonical);
        $venueIds = array_values(array_unique([...array_keys($byVenue), ...$bootstrap]));
        $legacy = collect($this->accountVenues->handle($canonical))->keyBy('id');

        $cards = Venue::query()->whereIn('id', $venueIds)->orderBy('name')->get()
            ->map(function (Venue $venue) use ($byVenue, $bootstrap, $legacy, $canonical): array {
                $contracts = collect($byVenue[$venue->id] ?? [])
                    ->sortByDesc(fn (ContractMembership $m): int =>
                        $this->isEffective($m->contract) ? 1000000000 + $m->contract->id : $m->contract->id)
                    ->map(function (ContractMembership $membership): array {
                        $contract = $membership->contract;
                        $effective = $this->isEffective($contract);
                        $granted = $contract->permissions->pluck('permission')->all();
                        $role = VenueMembershipAccessLevelEnum::tryFrom($membership->access_level);
                        $isVenue = $membership->scope_type === ContractMembershipScopeTypeEnum::VENUE;
                        return [
                            'id' => $contract->id,
                            'number' => $contract->number,
                            'status' => $contract->status->label(),
                            'effective' => $effective,
                            'role' => $role?->label() ?? $membership->access_level,
                            'scope' => $membership->scope_type->label(),
                            'assigned_by' => $contract->assignedByUser?->name
                                ?? ($contract->assigned_by
                                    ? 'Пользователь #'.$contract->assigned_by
                                    : (UserParticipationRoleAssignerEnum::tryFrom((string) $contract->assigner)?->label() ?? 'Не указано')),
                            'assigned_at' => $contract->assigned_at?->format('d.m.Y H:i'),
                            'created_at' => $contract->created_at?->format('d.m.Y H:i'),
                            'starts_at' => $contract->starts_at?->format('d.m.Y H:i'),
                            'expires_at' => $contract->expires_at?->format('d.m.Y H:i'),
                            'permissions' => array_map(static function (VenuePermissionEnum $permission) use ($granted, $effective, $role, $isVenue): array {
                                $allowed = in_array($permission->value, $granted, true);
                                // Match the legacy owner/admin override in VenueGroupOffers::canManage.
                                if ($isVenue && $permission === VenuePermissionEnum::MANAGE_GROUP_OFFERS
                                    && in_array($role, [VenueMembershipAccessLevelEnum::OWNER, VenueMembershipAccessLevelEnum::ADMIN], true)) {
                                    $allowed = true;
                                }
                                return ['label' => $permission->label(), 'code' => $permission->value, 'active' => $effective && $allowed];
                            }, VenuePermissionEnum::cases()),
                        ];
                    })->values()->all();

                return [
                    'venue' => $venue,
                    'legacy' => $legacy->get($venue->id),
                    'contracts' => $contracts,
                    'bootstrap' => in_array($venue->id, $bootstrap, true),
                    'manage_offers' => $this->offers->canManage($canonical, $venue),
                    'effective' => collect($contracts)->contains(fn (array $c): bool => $c['effective']),
                ];
            });

        $q = trim((string) ($filters['q'] ?? ''));
        $filtered = $cards->filter(static function (array $card) use ($filters, $q): bool {
            $venue = $card['venue'];
            if (! empty($filters['specialization']) && $venue->type->value !== $filters['specialization']) {
                return false;
            }
            if (! empty($filters['status'])) {
                $status = $filters['status'];
                if ($status === 'none' && $card['contracts'] !== []) return false;
                if ($status === 'active' && ! $card['effective']) return false;
                if ($status === 'inactive' && ($card['effective'] || $card['contracts'] === [])) return false;
            }
            if ($q !== '' && mb_stripos($venue->name.' '.$venue->alias.' '.$venue->raw_address, $q) === false) {
                return false;
            }
            return true;
        })->values();

        $page = max(1, LengthAwarePaginator::resolveCurrentPage());
        return (new LengthAwarePaginator(
            $filtered->forPage($page, 12)->values(),
            $filtered->count(),
            12,
            $page,
            ['path' => LengthAwarePaginator::resolveCurrentPath(), 'query' => request()->query()],
        ));
    }

    private function isEffective(Contract $contract): bool
    {
        $now = now();
        return $contract->status === ContractStatusEnum::ACTIVE
            && ($contract->starts_at === null || $contract->starts_at->lessThanOrEqualTo($now))
            && ($contract->expires_at === null || $contract->expires_at->greaterThan($now));
    }
}
