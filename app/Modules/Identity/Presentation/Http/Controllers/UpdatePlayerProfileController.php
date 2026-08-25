<?php

namespace App\Modules\Identity\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Application\Services\PlayerCharacterRenderService;
use App\Modules\Identity\Application\UseCases\UpdatePlayerProfileHandler;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleEnum;
use App\Modules\Identity\Presentation\Http\Requests\UpdatePlayerProfileRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class UpdatePlayerProfileController extends Controller
{
    public function __invoke(
        UpdatePlayerProfileRequest $request,
        UpdatePlayerProfileHandler $handler,
        PlayerCharacterRenderService $renderService,
    ): RedirectResponse {
        try {
            $facePhotoPath = $renderService->storeFaceReferenceData(
                $request->user(),
                $request->characterFacePhotoData(),
            );
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'character_face_photo' => $exception->getMessage(),
            ]);
        }

        $profile = $handler->handle(
            $request->user(),
            $request->profileData(),
            $request->positions(),
            $request->selfAssessment(),
            $request->characterAppearance(),
            $facePhotoPath,
        );

        if ($request->characterRenderRequested()) {
            $renderService->queueMock(
                $profile,
                $request->user(),
                $request->characterRenderMode(),
            );
        }

        return redirect()
            ->route(
                $request->shouldClose() ? 'account' : 'account.participation-role',
                $request->shouldClose() ? [] : [UserParticipationRoleEnum::PLAYER->value],
            )
            ->with('status', 'Профиль игрока обновлён.');
    }
}
