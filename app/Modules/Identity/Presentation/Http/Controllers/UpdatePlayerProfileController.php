<?php

namespace App\Modules\Identity\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Application\UseCases\StorePlayerCharacterFaceReferenceHandler;
use App\Modules\Identity\Application\UseCases\UpdatePlayerCharacterRenderModeHandler;
use App\Modules\Identity\Application\UseCases\UpdatePlayerProfileHandler;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleEnum;
use App\Modules\Identity\Presentation\Http\Requests\UpdatePlayerProfileRequest;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use InvalidArgumentException;
use RuntimeException;

final class UpdatePlayerProfileController extends Controller
{
    public function __invoke(
        UpdatePlayerProfileRequest $request,
        UpdatePlayerProfileHandler $handler,
        UpdatePlayerCharacterRenderModeHandler $renderModeHandler,
        StorePlayerCharacterFaceReferenceHandler $faceReferenceHandler,
    ): JsonResponse|RedirectResponse {
        if ($request->mutation() === 'render_mode') {
            return $this->updateRenderMode($request, $renderModeHandler);
        }

        if ($request->mutation() === 'face_reference') {
            return $this->storeFaceReference($request, $faceReferenceHandler);
        }

        $handler->handle(
            $request->user(),
            $request->profileData(),
            $request->positions(),
            $request->selfAssessment(),
            $request->characterAppearance(),
        );

        return redirect()
            ->route(
                $request->shouldClose() ? 'account' : 'account.participation-role',
                $request->shouldClose() ? [] : [UserParticipationRoleEnum::PLAYER->value],
            )
            ->with('status', 'Профиль игрока обновлён.');
    }

    private function updateRenderMode(
        UpdatePlayerProfileRequest $request,
        UpdatePlayerCharacterRenderModeHandler $handler,
    ): JsonResponse {
        $renderMode = $request->renderMode();

        if ($renderMode === null) {
            return response()->json(['message' => 'Не указан режим отображения персонажа.'], 422);
        }

        try {
            $profile = $handler->handle($request->user(), $renderMode);
        } catch (AuthorizationException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'render_mode' => '2d',
            ], 403);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'message' => $renderMode === '3d'
                ? '3D-режим включён.'
                : '2D-режим включён.',
            'render_mode' => data_get($profile->extra, 'character.render_mode', '2d'),
        ]);
    }

    private function storeFaceReference(
        UpdatePlayerProfileRequest $request,
        StorePlayerCharacterFaceReferenceHandler $handler,
    ): JsonResponse {
        $slot = $request->faceReferenceSlot();
        $file = $request->faceReferenceFile();

        if ($slot === null || $file === null) {
            return response()->json(['message' => 'Выберите фотографию лица и нужный ракурс.'], 422);
        }

        $path = $file->getRealPath();
        $contents = is_string($path) && $path !== '' ? @file_get_contents($path) : false;

        if (! is_string($contents) || $contents === '') {
            return response()->json(['message' => 'Не удалось прочитать фотографию.'], 422);
        }

        try {
            $profile = $request->user()->profile()->firstOrCreate();
            $result = $handler->handle($profile, $slot, $contents);
        } catch (InvalidArgumentException|RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Фото сохранено. Проверка ракурса через AI будет подключена на следующем этапе.',
            'slot' => $slot,
            'status' => 'stored',
            'width' => $result['width'],
            'height' => $result['height'],
        ]);
    }
}
