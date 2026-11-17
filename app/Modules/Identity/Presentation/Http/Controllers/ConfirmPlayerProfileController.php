<?php

namespace App\Modules\Identity\Presentation\Http\Controllers;

use App\Modules\Identity\Domain\Enums\UserParticipationRoleEnum;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleStatusEnum;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class ConfirmPlayerProfileController
{
    public function __invoke(Request $request): RedirectResponse
    {
        $canonicalId = $request->user()->canonical()->id;

        DB::transaction(function () use ($canonicalId): void {
            $user = User::query()->whereKey($canonicalId)->lockForUpdate()->firstOrFail();
            abort_if($user->isBlocked() || $user->trashed(), 403);

            $role = $user->participationRoles(false)
                ->where('role', UserParticipationRoleEnum::PLAYER->value)
                ->lockForUpdate()->firstOrFail();
            abort_unless($role->status === UserParticipationRoleStatusEnum::ACTIVE, 403);
            abort_if($role->confirmed_at !== null, 422, 'Профиль игрока уже подтверждён.');

            $profile = $user->playerProfile()->lockForUpdate()->first();
            abort_unless($profile?->height_cm !== null && $profile?->experience_started_year !== null,
                422, 'Для подтверждения игрока заполни рост и «Играю с».');

            $role->forceFill(['confirmed_at' => now()])->save();
        });

        return redirect()->route('account.participation-role', ['role' => 'player'])
            ->with('status', 'Профиль игрока подтверждён.');
    }
}
