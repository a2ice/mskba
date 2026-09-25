<?php

namespace App\Modules\Identity\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Ai\Domain\Enums\PlayerCharacterGenerationStatusEnum;
use App\Modules\Ai\Domain\Exceptions\AiServiceException;
use App\Modules\Ai\Domain\Models\PlayerCharacterGeneration;
use App\Modules\Contract\Domain\Enums\ContractStatusEnum;
use App\Modules\Identity\Application\UseCases\GeneratePlayerCharacterTwoDimensionalHandler;
use App\Modules\Identity\Application\UseCases\StorePlayerCharacterFaceReferenceHandler;
use App\Modules\Identity\Application\UseCases\UpdatePlayerCharacterRenderModeHandler;
use App\Modules\Identity\Application\UseCases\UpdatePlayerProfileHandler;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleEnum;
use App\Modules\Identity\Domain\Exceptions\PlayerCharacterFlowException;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Identity\Presentation\Http\Requests\UpdatePlayerProfileRequest;
use App\Modules\Pricing\Application\Services\PricingPriceResolver;
use App\Modules\Team\Domain\Enums\TeamInvitationStatusEnum;
use App\Modules\Team\Domain\Enums\TeamMemberTypeEnum;
use App\Modules\Team\Domain\Models\Team;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;
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
        PricingPriceResolver $prices,
    ): JsonResponse|RedirectResponse {
        if ($request->mutation() === 'render_mode') {
            return $this->updateRenderMode($request, $renderModeHandler);
        }

        if ($request->mutation() === 'face_reference') {
            return $this->storeFaceReference($request, $faceReferenceHandler);
        }

        if ($request->mutation() === 'generation_quote') {
            return $this->generationQuote($prices);
        }

        if ($request->mutation() === 'generation_preferences') {
            return $this->generationPreferences($request);
        }

        if ($request->mutation() === 'generation_history') {
            return $this->generationHistory($request);
        }

        if ($request->mutation() === 'generation_primary') {
            return $this->setPrimaryGeneration($request);
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
            'message' => 'Фото проверено и сохранено.',
            'slot' => $slot,
            'status' => 'stored',
            'width' => $result['width'],
            'height' => $result['height'],
            'preview_url' => route('account.player-character.face-reference', ['slot' => $slot]).'?v='.$result['media']->id,
        ]);
    }

    private function generationQuote(PricingPriceResolver $prices): JsonResponse
    {
        $price = $prices->resolve(GeneratePlayerCharacterTwoDimensionalHandler::SERVICE_CODE);

        if ($price === null) {
            return response()->json([
                'code' => 'pricing_unavailable',
                'message' => 'Цена генерации временно недоступна.',
            ], 503);
        }

        return response()->json([
            'price_minor' => (int) $price->amount_minor,
            'currency' => 'RUB',
        ]);
    }

    private function generationPreferences(UpdatePlayerProfileRequest $request): JsonResponse
    {
        $playerProfile = $request->user()->playerProfile()->first();
        $character = (array) data_get($playerProfile?->extra, 'character', []);
        $teams = $this->playerGenerationTeams($request->user());

        return response()->json([
            'height_cm' => $playerProfile?->height_cm,
            'weight_kg' => $playerProfile?->weight_kg !== null ? (float) $playerProfile->weight_kg : null,
            'body_type' => $playerProfile?->body_type?->value,
            'character' => $character,
            'generation_team_id' => data_get($character, 'generation_team_id'),
            'with_team_logo' => (bool) data_get($character, 'with_team_logo', false),
            'teams' => $teams->map(fn (Team $team): array => [
                'id' => (int) $team->id,
                'name' => (string) $team->name,
                'has_logo' => $team->logo !== null,
            ])->values(),
        ]);
    }

    private function generationHistory(UpdatePlayerProfileRequest $request): JsonResponse
    {
        $primaryId = (string) data_get(
            $request->user()->playerProfile()->first()?->extra,
            'character.primary_generation_id',
            '',
        );

        $generations = PlayerCharacterGeneration::query()
            ->whereIn('user_id', $request->user()->identityIds())
            ->whereIn('status', [
                PlayerCharacterGenerationStatusEnum::PENDING->value,
                PlayerCharacterGenerationStatusEnum::PROCESSING->value,
                PlayerCharacterGenerationStatusEnum::COMPLETED->value,
            ])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        $completed = $generations->first(fn (PlayerCharacterGeneration $generation): bool =>
            $generation->status === PlayerCharacterGenerationStatusEnum::COMPLETED
            && $generation->result_disk !== null
            && $generation->result_path !== null
        );

        $storedPrimary = $primaryId !== ''
            ? $generations->first(fn (PlayerCharacterGeneration $generation): bool =>
                $generation->public_id === $primaryId
                && $generation->status === PlayerCharacterGenerationStatusEnum::COMPLETED
            )
            : null;
        $effectivePrimaryId = $storedPrimary?->public_id ?: $completed?->public_id;

        return response()->json([
            'primary_generation_id' => $effectivePrimaryId,
            'generations' => $generations->map(function (PlayerCharacterGeneration $generation) use ($effectivePrimaryId, $completed): array {
                $isCompleted = $generation->status === PlayerCharacterGenerationStatusEnum::COMPLETED
                    && $generation->result_disk !== null
                    && $generation->result_path !== null;

                return [
                    'generation_id' => $generation->public_id,
                    'status' => $generation->status->value,
                    'image_url' => $isCompleted
                        ? route('account.player-character.generations.image', [
                            'generation' => $generation->public_id,
                        ]).'?v='.$generation->updated_at?->getTimestamp()
                        : null,
                    'is_primary' => $isCompleted && $generation->public_id === $effectivePrimaryId,
                    'is_new' => $isCompleted && $generation->public_id === $completed?->public_id,
                    'created_at' => $generation->created_at?->toIso8601String(),
                ];
            })->values(),
        ], 200, ['Cache-Control' => 'private, no-store']);
    }

    private function setPrimaryGeneration(UpdatePlayerProfileRequest $request): JsonResponse
    {
        $generationId = $request->generationId();
        if ($generationId === null) {
            return response()->json([
                'code' => 'generation_missing',
                'message' => 'Не выбрана генерация.',
            ], 422);
        }

        $generation = PlayerCharacterGeneration::query()
            ->whereIn('user_id', $request->user()->identityIds())
            ->where('public_id', $generationId)
            ->where('status', PlayerCharacterGenerationStatusEnum::COMPLETED->value)
            ->whereNotNull('result_disk')
            ->whereNotNull('result_path')
            ->first();

        if ($generation === null) {
            return response()->json([
                'code' => 'generation_unavailable',
                'message' => 'Эта генерация недоступна.',
            ], 404);
        }

        $playerProfile = $request->user()->playerProfile()->first();
        if ($playerProfile === null) {
            return response()->json([
                'code' => 'profile_missing',
                'message' => 'Профиль игрока не найден.',
            ], 422);
        }

        $extra = is_array($playerProfile->extra) ? $playerProfile->extra : [];
        $character = is_array($extra['character'] ?? null) ? $extra['character'] : [];
        $character['primary_generation_id'] = $generation->public_id;
        $extra['character'] = $character;
        $playerProfile->forceFill(['extra' => $extra])->save();

        return response()->json([
            'message' => 'Главное изображение обновлено.',
            'generation_id' => $generation->public_id,
            'image_url' => route('account.player-character.generations.image', [
                'generation' => $generation->public_id,
            ]).'?v='.$generation->updated_at?->getTimestamp(),
        ]);
    }

    /** @return Collection<int, Team> */
    private function playerGenerationTeams(User $user): Collection
    {
        return Team::query()
            ->with('logo')
            ->whereNull('temporary_for_event_id')
            ->whereHas('memberships', fn ($memberships) => $memberships
                ->whereIn('user_id', $user->identityIds())
                ->where('invitation_status', TeamInvitationStatusEnum::ACCEPTED->value)
                ->withSportRole(TeamMemberTypeEnum::PLAYER)
                ->whereHas('contract', fn ($contract) => $contract
                    ->where('status', ContractStatusEnum::ACTIVE->value)))
            ->orderBy('name')
            ->get(['id', 'name']);
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

        if ($result['status'] === 'pending') {
            return response()->json([
                'message' => 'Генерация запущена.',
                'status' => 'pending',
                'generation_id' => $result['generation_id'],
                'status_url' => route('account.player-character.generations.show', [
                    'generation' => $result['generation_id'],
                ]),
                'price_minor' => $result['price_minor'],
                'available_minor' => $result['available_minor'],
                'face_previews' => $facePreviews,
            ], 202);
        }

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
     * @param  array<string, int>  $mediaIds
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
