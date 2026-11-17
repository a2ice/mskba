<?php

namespace App\Modules\Venue\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Contract\Domain\Enums\ContractMembershipScopeTypeEnum;
use App\Modules\Contract\Domain\Enums\ContractStatusEnum;
use App\Modules\Contract\Domain\Enums\VenueMembershipAccessLevelEnum;
use App\Modules\Contract\Domain\Models\ContractMembership;
use App\Modules\Identity\Application\Services\SearchDiscoverableUsers;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Application\Services\VenueCommercialAccess;
use App\Modules\Venue\Application\UseCases\ManageVenueMembershipHandler;
use App\Modules\Venue\Domain\Enums\VenuePermissionEnum;
use App\Modules\Venue\Domain\Exceptions\VenueMembershipException;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\Venue\Domain\Models\VenueCourt;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

final class VenueCommercialMembershipController extends Controller
{
    public function index(Request $request, Venue $venue, VenueCommercialAccess $access): Response
    {
        $court = $this->requestedCourt($venue, $request->query('court_id'));
        $this->authorizeScope($request, $venue, $court, $access);

        return ThemeResolver::page('venues.commercial-memberships', [
            'venue' => $venue,
            'memberships' => $this->activeMemberships($venue, $court)->with(['user.profile', 'contract.permissions'])->get(),
            'courts' => $venue->courts()->get(),
            'selectedCourt' => $court,
            'roles' => $this->assignableRoles(),
            'permissions' => VenuePermissionEnum::cases(),
        ]);
    }

    public function candidates(
        Request $request,
        Venue $venue,
        VenueCommercialAccess $access,
        SearchDiscoverableUsers $users,
    ): JsonResponse {
        $court = $this->requestedCourt($venue, $request->query('court_id'));
        $this->authorizeScope($request, $venue, $court, $access);
        $validated = $request->validate(['q' => ['required', 'string', 'min:2', 'max:100']]);
        $excluded = $this->activeMemberships($venue, $court)->pluck('user_id')->all();

        $candidates = $users->handle($request->user(), $validated['q'], $excluded)
            ->filter(fn (User $user): bool => $user->status === UserStatusEnum::CONFIRMED)
            ->map(fn (User $user): array => [
                'id' => $user->id,
                'name' => trim(($user->profile?->first_name ?? '').' '.($user->profile?->last_name ?? '')) ?: $user->username,
                'meta' => $user->username ? '@'.$user->username : null,
            ])->values();

        return response()->json(['candidates' => $candidates]);
    }

    public function store(
        Request $request,
        Venue $venue,
        ManageVenueMembershipHandler $memberships,
        VenueCommercialAccess $access,
    ): RedirectResponse {
        $validated = $this->validateMembership($request, true, $venue);
        $court = $this->requestedCourt($venue, $validated['court_id'] ?? null);
        $this->authorizeScope($request, $venue, $court, $access);

        try {
            $memberships->grant(
                $venue,
                User::query()->findOrFail($validated['user_id']),
                VenueMembershipAccessLevelEnum::from($validated['role']),
                $request->user(),
                $this->permissions($validated),
                $court,
            );
        } catch (VenueMembershipException $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        }

        return back()->with('status', 'Коммерческая роль выдана.');
    }

    public function update(
        Request $request,
        Venue $venue,
        ContractMembership $membership,
        ManageVenueMembershipHandler $memberships,
        VenueCommercialAccess $access,
    ): RedirectResponse {
        $court = $this->membershipCourt($venue, $membership);
        $this->authorizeScope($request, $venue, $court, $access);
        $validated = $this->validateMembership($request, false, $venue);

        try {
            $memberships->change(
                $venue,
                $membership,
                VenueMembershipAccessLevelEnum::from($validated['role']),
                $request->user(),
                $this->permissions($validated),
            );
        } catch (VenueMembershipException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('status', 'Роль и права обновлены.');
    }

    public function destroy(
        Request $request,
        Venue $venue,
        ContractMembership $membership,
        ManageVenueMembershipHandler $memberships,
        VenueCommercialAccess $access,
    ): RedirectResponse {
        $court = $this->membershipCourt($venue, $membership);
        $this->authorizeScope($request, $venue, $court, $access);
        try {
            $memberships->revoke($venue, $membership, $request->user());
        } catch (VenueMembershipException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('status', 'Коммерческая роль отозвана.');
    }

    /** @return array<string, mixed> */
    private function validateMembership(Request $request, bool $withUser, Venue $venue): array
    {
        return $request->validate([
            'user_id' => [$withUser ? 'required' : 'nullable', 'integer', 'exists:users,id'],
            'role' => ['required', Rule::enum(VenueMembershipAccessLevelEnum::class)],
            'court_id' => ['nullable', 'integer', Rule::exists('venue_courts', 'id')
                ->where('venue_id', $venue->id)->whereNull('deleted_at')],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string', Rule::enum(VenuePermissionEnum::class)],
        ]);
    }

    /** @param array<string, mixed> $validated
     * @return array<VenuePermissionEnum>|null
     */
    private function permissions(array $validated): ?array
    {
        if (! array_key_exists('permissions', $validated)) {
            return null;
        }

        return array_map(
            static fn (string $permission): VenuePermissionEnum => VenuePermissionEnum::from($permission),
            $validated['permissions'] ?? [],
        );
    }

    /** @return array<VenueMembershipAccessLevelEnum> */
    private function assignableRoles(): array
    {
        return [
            VenueMembershipAccessLevelEnum::MANAGER,
            VenueMembershipAccessLevelEnum::BOOKING_OPERATOR,
            VenueMembershipAccessLevelEnum::FINANCE_VIEWER,
        ];
    }

    private function requestedCourt(Venue $venue, mixed $courtId): ?VenueCourt
    {
        if ($courtId === null || $courtId === '' || $courtId === '0') {
            return null;
        }
        if (! ctype_digit((string) $courtId)) {
            abort(404);
        }
        return $venue->courts()->findOrFail((int) $courtId);
    }

    private function membershipCourt(Venue $venue, ContractMembership $membership): ?VenueCourt
    {
        if ($membership->scope_type === ContractMembershipScopeTypeEnum::VENUE
            && (int) $membership->scope_id === (int) $venue->id) {
            return null;
        }
        if ($membership->scope_type === ContractMembershipScopeTypeEnum::VENUE_COURT) {
            return $venue->courts()->findOrFail((int) $membership->scope_id);
        }
        abort(404);
    }

    private function authorizeScope(
        Request $request, Venue $venue, ?VenueCourt $court, VenueCommercialAccess $access
    ): void {
        abort_unless($request->user() !== null && ($court === null
            ? $access->allows($request->user(), $venue, VenuePermissionEnum::MANAGE_MEMBERSHIPS)
            : $access->allowsForCourt($request->user(), $court, VenuePermissionEnum::MANAGE_MEMBERSHIPS)), 403);
    }

    private function activeMemberships(Venue $venue, ?VenueCourt $court = null): Builder
    {
        return ContractMembership::query()
            ->where('scope_type', ($court === null
                ? ContractMembershipScopeTypeEnum::VENUE
                : ContractMembershipScopeTypeEnum::VENUE_COURT)->value)
            ->where('scope_id', $court?->id ?? $venue->id)
            ->whereIn('access_level', array_map(
                static fn (VenueMembershipAccessLevelEnum $role): string => $role->value,
                VenueMembershipAccessLevelEnum::cases(),
            ))
            ->whereHas('contract', fn (Builder $query) => $query->where('status', ContractStatusEnum::ACTIVE->value));
    }
}
