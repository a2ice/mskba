<?php

namespace App\Modules\Tournament\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Identity\Application\Services\SearchDiscoverableUsers;
use App\Modules\Identity\Domain\Enums\UserPrivacySettingTypeEnum;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Team\Domain\Enums\TeamInvitationStatusEnum;
use App\Modules\Tournament\Application\Services\TournamentAccess;
use App\Modules\Tournament\Domain\Enums\TournamentPermissionEnum;
use App\Modules\Tournament\Domain\Models\Tournament;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class TournamentStaffCandidateSearchController extends Controller
{
    public function __invoke(
        Request $request,
        string $tournament,
        CurrentActorResolver $actors,
        TournamentAccess $access,
        SearchDiscoverableUsers $users,
    ): JsonResponse {
        $item = Tournament::query()->whereRouteIdentifier($tournament)->firstOrFail();
        $actor = $actors->resolveForRequest($request);
        abort_if($actor === null || ! $access->allows($item, $actor, TournamentPermissionEnum::MANAGE_STAFF), 403);
        $data = $request->validate(['q' => ['required', 'string', 'min:2', 'max:100']]);
        $excluded = $item->staffMemberships()
            ->whereIn('invitation_status', [TeamInvitationStatusEnum::PENDING->value, TeamInvitationStatusEnum::ACCEPTED->value])
            ->pluck('user_id')->all();

        $candidates = $users->handle(
            $actor->user,
            $data['q'],
            $excluded,
            requiredAccess: UserPrivacySettingTypeEnum::GROUP_INVITATIONS,
        )->filter(fn (User $user): bool => $user->status === UserStatusEnum::CONFIRMED)
            ->map(fn (User $user): array => [
                'id' => $user->id,
                'name' => trim(($user->profile?->first_name ?? '').' '.($user->profile?->last_name ?? '')) ?: $user->username,
                'meta' => '@'.$user->username,
            ])->values();

        return response()->json(['candidates' => $candidates]);
    }
}
