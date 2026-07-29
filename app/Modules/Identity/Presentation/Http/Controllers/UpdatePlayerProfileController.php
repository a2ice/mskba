<?php

namespace App\Modules\Identity\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Application\UseCases\UpdatePlayerProfileHandler;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleEnum;
use App\Modules\Identity\Presentation\Http\Requests\UpdatePlayerProfileRequest;
use Illuminate\Http\RedirectResponse;

final class UpdatePlayerProfileController extends Controller
{
    public function __invoke(
        UpdatePlayerProfileRequest $request,
        UpdatePlayerProfileHandler $handler,
    ): RedirectResponse {
        $handler->handle(
            $request->user(),
            $request->profileData(),
            $request->positions(),
            $request->selfAssessment(),
        );

        return redirect()
            ->route(
                $request->shouldClose() ? 'account' : 'account.participation-role',
                $request->shouldClose() ? [] : [UserParticipationRoleEnum::PLAYER->value],
            )
            ->with('status', 'Профиль игрока обновлён.');
    }
}
