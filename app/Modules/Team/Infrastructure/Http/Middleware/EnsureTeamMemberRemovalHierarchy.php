<?php

namespace App\Modules\Team\Infrastructure\Http\Middleware;

use App\Modules\Contract\Domain\Enums\ContractStatusEnum;
use App\Modules\Contract\Domain\Enums\TeamMembershipAccessLevelEnum;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Team\Application\Services\TeamManagementAccess;
use App\Modules\Team\Application\Services\TeamMembershipHierarchy;
use App\Modules\Team\Domain\Enums\TeamInvitationStatusEnum;
use App\Modules\Team\Domain\Models\Team;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureTeamMemberRemovalHierarchy
{
    public function __construct(
        private readonly CurrentActorResolver $actors,
        private readonly TeamManagementAccess $access,
        private readonly TeamMembershipHierarchy $hierarchy,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->routeIs('teams.members.destroy')) {
            return $next($request);
        }

        $teamParameter = $request->route('team');
        $membershipParameter = $request->route('membership');
        if ((! is_string($teamParameter) && ! $teamParameter instanceof Team) || ! is_numeric($membershipParameter)) {
            return $next($request);
        }

        $team = $teamParameter instanceof Team
            ? $teamParameter
            : Team::query()->whereRouteIdentifier($teamParameter)->first();
        $actor = $this->actors->resolveForRequest($request);
        if ($team === null || $actor?->user_id === null) {
            return $next($request);
        }
        $identityIds = $actor->user?->canonical()->identityIds() ?? [];

        $target = $team->memberships()
            ->whereKey((int) $membershipParameter)
            ->where('invitation_status', TeamInvitationStatusEnum::ACCEPTED->value)
            ->whereHas('contract', fn ($query) => $query->where('status', ContractStatusEnum::ACTIVE->value))
            ->first();
        if ($target === null) {
            return $next($request);
        }

        if ($target->access_level === TeamMembershipAccessLevelEnum::OWNER->value) {
            return $this->deny($request, 'Владельца команды удалить нельзя.');
        }

        if (in_array((int) $target->user_id, $identityIds, true)) {
            return $this->deny($request, 'Нельзя исключить самого себя через управление командой.');
        }

        if ($target->is_captain) {
            return $this->deny($request, 'Капитана нельзя исключить из команды. Сначала назначьте другого капитана.');
        }

        if ($this->access->isCreator($team, $actor)) {
            return $next($request);
        }

        $actorMembership = $team->memberships()
            ->whereIn('user_id', $identityIds)
            ->where('invitation_status', TeamInvitationStatusEnum::ACCEPTED->value)
            ->whereHas('contract', fn ($query) => $query->where('status', ContractStatusEnum::ACTIVE->value))
            ->first();

        if ($actorMembership === null || ! $this->hierarchy->canRemove($actorMembership, $target)) {
            return $this->deny(
                $request,
                'Нельзя исключить участника с равным или более высоким уровнем управления.',
            );
        }

        return $next($request);
    }

    private function deny(Request $request, string $message): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $message], 422);
        }

        return back()->with('error', $message);
    }
}
