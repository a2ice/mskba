<?php

namespace App\Modules\SportsSection\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Application\Services\SearchDiscoverableUsers;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleEnum;
use App\Modules\Identity\Domain\Enums\UserPrivacySettingTypeEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\SportsSection\Application\Services\SportsSectionAccess;
use App\Modules\SportsSection\Domain\Models\SportsSection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class SportsSectionCandidateController extends Controller
{
    public function __invoke(Request $request, SportsSection $sportsSection, SportsSectionAccess $access, SearchDiscoverableUsers $users): JsonResponse
    {
        abort_unless($access->activeMemberships($sportsSection)->whereIn('user_id', $request->user()->canonical()->identityIds())->exists(), 403);
        $data = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:100'],
            'role' => ['required', Rule::in([UserParticipationRoleEnum::COACH->value, UserParticipationRoleEnum::PLAYER->value])],
        ]);
        $role = UserParticipationRoleEnum::from($data['role']);
        $excluded = $role === UserParticipationRoleEnum::COACH
            ? $access->activeMemberships($sportsSection)->pluck('user_id')->all()
            : $sportsSection->traineeMemberships()->where('status', 'active')->pluck('user_id')->all();
        $candidates = $users->handle(
            $request->user(),
            $data['q'],
            $excluded,
            requiredAccess: UserPrivacySettingTypeEnum::GROUP_INVITATIONS,
        )->filter(fn (User $user): bool => $user->isConfirmed() && $user->hasActiveRole($role->value))
            ->map(fn (User $user): array => [
                'id' => $user->id,
                'name' => trim(($user->profile?->first_name ?? '').' '.($user->profile?->last_name ?? '')) ?: $user->username,
                'meta' => '@'.$user->username,
            ])->values();

        return response()->json(['candidates' => $candidates]);
    }
}
