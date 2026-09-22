<?php

namespace App\Modules\Identity\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Ai\Domain\Exceptions\AiServiceException;
use App\Modules\Identity\Application\UseCases\GeneratePlayerCharacterTwoDimensionalHandler;
use App\Modules\Identity\Application\UseCases\StorePlayerCharacterFaceReferenceHandler;
use App\Modules\Identity\Application\UseCases\UpdatePlayerCharacterRenderModeHandler;
use App\Modules\Identity\Application\UseCases\UpdatePlayerProfileHandler;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleEnum;
use App\Modules\Identity\Domain\Exceptions\PlayerCharacterFlowException;
use App\Modules\Identity\Presentation\Http\Requests\UpdatePlayerProfileRequest;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;

final class UpdatePlayerProfileController extends Controller
{
    public function __invoke(
        UpdatePlayerProfileRequest $request,
        UpdatePlayerProfileHandler $handler,
        UpdatePlayerCharacterRenderModeHandler $renderModeHandler,
        StorePlayerCharacterFaceReferenceHandler $faceReferenceHandler,
        GeneratePlayerCharacterTwoDimensionalHandler $generationHandler,
    ): JsonResponse|RedirectResponse {
        if ($request->mutation() === 'render_mode') {
            return $this->updateRenderMode($request, $renderModeHandler);
        }

        if ($request->mutation() === 'face_reference') {
            return $this->storeFaceReference($request, $faceReferenceHandler);
        }

        if ($request->mutation() === 'generate_2d') {
            return $this->generateTwoDimensional($request, $generationHandler);
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
            return response()->json([
                'code' => 'face_reference_missing',
                'message' => 'Выберите фотографию лица и нужный ракурс.',
            ], 422);
        }

        $path = $file->getRealPath();
        $contents = is_string($path) && $path !== '' ? @file_get_contents($path) : false;

        if (! is_string($contents) || $contents === '') {
            return response()->json([
                'code' => 'face_reference_invalid_file',
                'message' => 'Не удалось прочитать фотографию.',
            ], 422);
        }

        $profile = $request->user()->profile()->first();

        if ($profile === null) {
            return response()->json([
                'code' => 'profile_missing',
                'message' => 'Сначала заполните базовый профиль пользователя.',
            ], 422);
        }

        try {
            $result = $handler->handle($profile, $slot, $contents);
        } catch (AiServiceException $exception) {
            $this->logAiFailure($request, 'face_reference', $exception->errorCode, $exception->getMessage());

            return response()->json([
                'code' => $exception->errorCode,
                'message' => $exception->getMessage(),
            ], $exception->httpStatus);
        } catch (PlayerCharacterFlowException $exception) {
            return response()->json(array_merge([
                'code' => $exception->errorCode,
                'message' => $exception->getMessage(),
            ], $exception->context), $exception->httpStatus);
        } catch (InvalidArgumentException|RuntimeException $exception) {
            return response()->json([
                'code' => 'face_reference_invalid_file',
                'message' => $exception->getMessage(),
            ], 422);
        }

        return response()->json([
            'message' => 'Фото проверено AI и сохранено.',
            'slot' => $slot,
            'status' => 'stored',
            'width' => $result['width'],
            'height' => $result['height'],
            'preview_url' => route('account.player-character.face-reference', ['slot' => $slot]).'?v='.$result['media']->id,
        ]);
    }

    private function generateTwoDimensional(
        UpdatePlayerProfileRequest $request,
        GeneratePlayerCharacterTwoDimensionalHandler $handler,
    ): JsonResponse {
        $pendingFaceReferences = [];

        foreach ($request->generationFaceReferenceFiles() as $slot => $file) {
            $path = $file->getRealPath();
            $contents = is_string($path) && $path !== '' ? @file_get_contents($path) : false;

            if (! is_string($contents) || $contents === '') {
                return response()->json([
                    'code' => 'face_reference_invalid_file',
                    'message' => 'Не удалось прочитать фотографию лица.',
                    'slot' => $slot,
                ], 422);
            }

            $pendingFaceReferences[$slot] = $contents;
        }

        try {
            $result = $handler->handle(
                $request->user(),
                $request->generationOptions(),
                $pendingFaceReferences,
            );
        } catch (AiServiceException $exception) {
            $this->logAiFailure($request, 'generate_2d', $exception->errorCode, $exception->getMessage());

            return response()->json([
                'code' => $exception->errorCode,
                'message' => $exception->getMessage(),
                'face_previews' => $this->facePreviewUrls(
                    (array) ($exception->context['validated_face_media_ids'] ?? []),
                ),
            ], $exception->httpStatus);
        } catch (PlayerCharacterFlowException $exception) {
            return response()->json(array_merge([
                'code' => $exception->errorCode,
                'message' => $exception->getMessage(),
            ], $exception->context), $exception->httpStatus);
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'code' => 'face_reference_invalid_file',
                'message' => $exception->getMessage(),
            ], 422);
        } catch (RuntimeException $exception) {
            Log::error('Player character generation failed unexpectedly.', [
                'user_id' => $request->user()->id,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return response()->json([
                'code' => 'generation_failed',
                'message' => 'Не удалось сгенерировать модель игрока.',
            ], 502);
        }

        $facePreviews = $this->facePreviewUrls($result['validated_face_media_ids']);

        return response()->json([
            'message' => '2D-модель сгенерирована.',
            'status' => 'generated',
            'price_minor' => $result['price_minor'],
            'available_minor' => $result['available_minor'],
            'image_data_url' => 'data:'.$result['image_mime'].';base64,'.base64_encode($result['image_contents']),
            'face_previews' => $facePreviews,
        ]);
    }

    /**
     * @param array<string, int> $mediaIds
     * @return array<string, string>
     */
    private function facePreviewUrls(array $mediaIds): array
    {
        $previews = [];

        foreach ($mediaIds as $slot => $mediaId) {
            if (! in_array($slot, ['front', 'left', 'right'], true) || $mediaId < 1) {
                continue;
            }

            $previews[$slot] = route('account.player-character.face-reference', ['slot' => $slot]).'?v='.$mediaId;
        }

        return $previews;
    }

    private function logAiFailure(
        UpdatePlayerProfileRequest $request,
        string $action,
        string $code,
        string $message,
    ): void {
        Log::warning('Player character AI request failed.', [
            'user_id' => $request->user()->id,
            'action' => $action,
            'code' => $code,
            'message' => $message,
        ]);
    }
}
