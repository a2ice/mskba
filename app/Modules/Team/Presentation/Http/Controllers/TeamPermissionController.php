<?php

namespace App\Modules\Team\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Contract\Domain\Enums\ContractStatusEnum;
use App\Modules\Contract\Domain\Enums\TeamMembershipAccessLevelEnum;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Team\Application\Services\TeamManagementAccess;
use App\Modules\Team\Domain\Enums\TeamInvitationStatusEnum;
use App\Modules\Team\Domain\Enums\TeamPermissionEnum;
use App\Modules\Team\Domain\Models\Team;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class TeamPermissionController extends Controller
{
    public function update(string $team, int $membership, Request $request, CurrentActorResolver $actors, TeamManagementAccess $access): JsonResponse
    {
        $item = Team::query()->whereRouteIdentifier($team)->firstOrFail();
        $actor = $actors->resolveForRequest($request);
        abort_if($actor === null || ! $access->allows($item, $actor, TeamPermissionEnum::MANAGE_PERMISSIONS), 403);
        $data = $request->validate([
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string', 'distinct', Rule::enum(TeamPermissionEnum::class)],
        ]);
        $member = $item->memberships()->whereKey($membership)
            ->where('invitation_status', TeamInvitationStatusEnum::ACCEPTED->value)
            ->whereHas('contract', fn ($query) => $query->where('status', ContractStatusEnum::ACTIVE->value))
            ->firstOrFail();
        abort_if($member->access_level === TeamMembershipAccessLevelEnum::OWNER->value, 422, 'Права создателя команды являются полными и не изменяются.');

        DB::transaction(function () use ($member, $data): void {
            $contract = $member->contract()->lockForUpdate()->firstOrFail();
            $contract->permissions()->delete();
            $contract->permissions()->createMany(array_map(fn (string $permission) => ['permission' => $permission], $data['permissions']));
        });

        return response()->json(['message' => 'Договорные права обновлены.']);
    }
}
