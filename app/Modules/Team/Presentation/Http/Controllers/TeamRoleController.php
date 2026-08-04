<?php

namespace App\Modules\Team\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Contract\Domain\Enums\ContractStatusEnum;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Team\Application\Services\TeamManagementAccess;
use App\Modules\Team\Domain\Enums\TeamInvitationStatusEnum;
use App\Modules\Team\Domain\Enums\TeamMemberTypeEnum;
use App\Modules\Team\Domain\Enums\TeamPermissionEnum;
use App\Modules\Team\Domain\Models\Team;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class TeamRoleController extends Controller
{
    public function captain(string $team, int $membership, Request $request, CurrentActorResolver $actors, TeamManagementAccess $access): JsonResponse
    {
        $item = Team::query()->whereRouteIdentifier($team)->firstOrFail();
        $actor = $actors->resolveForRequest($request);
        abort_if($actor === null || ! $access->allows($item, $actor, TeamPermissionEnum::MANAGE_ROLES), 403);
        $captain = $item->memberships()->whereKey($membership)
            ->where('member_type', TeamMemberTypeEnum::PLAYER->value)
            ->where('invitation_status', TeamInvitationStatusEnum::ACCEPTED->value)
            ->whereHas('contract', fn ($query) => $query->where('status', ContractStatusEnum::ACTIVE->value))
            ->firstOrFail();
        DB::transaction(function () use ($item, $captain): void {
            Team::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();
            $item->memberships()->update(['is_captain' => false]);
            $captain->update(['is_captain' => true]);
        });

        return response()->json(['message' => 'Капитан назначен.', 'membership_id' => $captain->id]);
    }
}
