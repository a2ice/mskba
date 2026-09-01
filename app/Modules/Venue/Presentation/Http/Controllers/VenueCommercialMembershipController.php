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
        abort_unless($access->allows($request->user(), $venue, VenuePermissionEnum::MANAGE_MEMBERSHIPS), 403);

        return ThemeResolver::page('venues.commercial-memberships', [
            'venue' => $venue,
            'memberships' => $this->activeMemberships($venue)->with(['user.profile', 'contract.permissions'])->get(),
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
        abort_unless($access->allows($request->user(), $venue, VenuePermissionEnum::MANAGE_MEMBERSHIPS), 403);
        $validated = $request->validate(['q' => ['required', 'string', 'min:2', 'max:100']]);
        $excluded = $this->activeMemberships($venue)->pluck('user_id')->all();

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
        abort_unless($access->allows($request->user(), $venue, VenuePermissionEnum::MANAGE_MEMBERSHIPS), 403);
        $validated = $this->validateMembership($request, true);

        try {
            $memberships->grant(
                $venue,
                User::query()->findOrFail($validated['user_id']),
                VenueMembershipAccessLevelEnum::from($validated['role']),
                $request->user(),
                $this->permissions($validated),
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
        abort_unless($access->allows($request->user(), $venue, VenuePermissionEnum::MANAGE_MEMBERSHIPS), 403);
        $validated = $this->validateMembership($request, false);

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
        abort_unless($access->allows($request->user(), $venue, VenuePermissionEnum::MANAGE_MEMBERSHIPS), 403);
        try {
            $memberships->revoke($venue, $membership, $request->user());
        } catch (VenueMembershipException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('status', 'Коммерческая роль отозвана.');
    }

    /** @return array<string, mixed> */
    private function validateMembership(Request $request, bool $withUser): array
    {
        return $request->validate([
            'user_id' => [$withUser ? 'required' : 'nullable', 'integer', 'exists:users,id'],
            'role' => ['required', Rule::enum(VenueMembershipAccessLevelEnum::class)],
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

    private function activeMemberships(Venue $venue): Builder
    {
        return ContractMembership::query()
            ->where('scope_type', ContractMembershipScopeTypeEnum::VENUE->value)
            ->where('scope_id', $venue->id)
            ->whereIn('access_level', array_map(
                static fn (VenueMembershipAccessLevelEnum $role): string => $role->value,
                VenueMembershipAccessLevelEnum::cases(),
            ))
            ->whereHas('contract', fn (Builder $query) => $query->where('status', ContractStatusEnum::ACTIVE->value));
    }
}
