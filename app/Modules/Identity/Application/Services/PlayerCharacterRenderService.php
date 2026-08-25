<?php

namespace App\Modules\Identity\Application\Services;

use App\Modules\Identity\Domain\Models\Participation\PlayerProfile;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Identity\Domain\Support\PlayerCharacterAppearanceOptions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class PlayerCharacterRenderService
{
    private const MOCK_DELAY_SECONDS = 4;

    /**
     * Store the browser-normalized face reference privately. It is deliberately
     * never exposed through a public storage URL; a future OpenAI provider will
     * read it server-side only.
     */
    public function storeFaceReferenceData(User $user, ?string $dataUrl): ?string
    {
        if ($dataUrl === null || $dataUrl === '') {
            return null;
        }

        if (! preg_match('/^data:image\/(jpeg|png|webp);base64,(.+)$/s', $dataUrl, $matches)) {
            throw new InvalidArgumentException('Не удалось прочитать фото лица. Выберите JPG, PNG или WebP.');
        }

        $binary = base64_decode($matches[2], true);
        if ($binary === false || $binary === '') {
            throw new InvalidArgumentException('Не удалось прочитать фото лица.');
        }

        if (strlen($binary) > 900_000) {
            throw new InvalidArgumentException('Фото лица получилось слишком большим. Выберите другое изображение.');
        }

        $imageInfo = @getimagesizefromstring($binary);
        if ($imageInfo === false || ($imageInfo[0] ?? 0) < 64 || ($imageInfo[1] ?? 0) < 64) {
            throw new InvalidArgumentException('Фото лица повреждено или слишком маленькое.');
        }

        $extension = match ($matches[1]) {
            'png' => 'png',
            'webp' => 'webp',
            default => 'jpg',
        };
        $path = sprintf(
            'player-character/faces/%d/%s.%s',
            $user->getKey(),
            Str::uuid()->toString(),
            $extension,
        );

        Storage::disk('local')->put($path, $binary);

        return $path;
    }

    /**
     * Queue the temporary fixture renderer. The shape mirrors the state that a
     * real asynchronous OpenAI image provider will later own.
     *
     * @return array<string, mixed>
     */
    public function queueMock(PlayerProfile $profile, User $user, string $mode = 'success'): array
    {
        $mode = $mode === 'error' ? 'error' : 'success';
        $gender = PlayerCharacterAppearanceOptions::normalizeGender($user->profile?->gender?->value);
        $variant = (abs(crc32((string) $user->getKey().'|'.now()->format('YmdHis'))) % 2) + 1;
        $requestedAt = now();
        $resultUrl = sprintf('/images/player-character/mock/%s-%d.png', $gender, $variant);
        $extra = $profile->extra ?? [];
        $character = is_array($extra['character'] ?? null)
            ? $extra['character']
            : PlayerCharacterAppearanceOptions::defaults($gender);

        $state = [
            'driver' => 'mock',
            'request_id' => Str::uuid()->toString(),
            'status' => 'generating',
            'mode' => $mode,
            'requested_at' => $requestedAt->toIso8601String(),
            'ready_at' => $requestedAt->copy()->addSeconds(self::MOCK_DELAY_SECONDS)->toIso8601String(),
            'result_url' => $resultUrl,
            'error' => null,
            'payload' => [
                'gender' => $gender,
                'height_cm' => $profile->height_cm,
                'weight_kg' => $profile->weight_kg !== null ? (float) $profile->weight_kg : null,
                'body_type' => $profile->body_type?->value,
                'appearance' => [
                    'skin_tone' => $character['skin_tone'] ?? null,
                    'hairstyle' => $character['hairstyle'] ?? null,
                    'hair_color' => $character['hair_color'] ?? null,
                    'facial_hair' => $character['facial_hair'] ?? null,
                    'piercings' => array_values((array) ($character['piercings'] ?? [])),
                    'tattoos' => array_values((array) ($character['tattoos'] ?? [])),
                    'tattoo_note' => $character['tattoo_note'] ?? '',
                    'has_face_reference' => filled($character['face_photo_path'] ?? null),
                ],
            ],
        ];

        $extra['character_render'] = $state;
        $profile->forceFill(['extra' => $extra])->save();

        return $state;
    }

    /**
     * Resolve a stored mock state for presentation without mutating it. A real
     * provider will replace this with a persisted job/result status endpoint.
     *
     * @return array<string, mixed>
     */
    public function effectiveState(?PlayerProfile $profile): array
    {
        $state = is_array($profile?->extra['character_render'] ?? null)
            ? $profile->extra['character_render']
            : [];

        if ($state === []) {
            return [
                'status' => 'idle',
                'mode' => 'success',
                'ready_at' => null,
                'result_url' => null,
                'error' => null,
            ];
        }

        if (($state['status'] ?? null) !== 'generating') {
            return $state;
        }

        $readyAt = isset($state['ready_at']) ? Carbon::parse($state['ready_at']) : null;
        if ($readyAt === null || $readyAt->isFuture()) {
            return $state;
        }

        if (($state['mode'] ?? 'success') === 'error') {
            return array_merge($state, [
                'status' => 'error',
                'error' => 'Не удалось собрать игровой образ. Это тестовая ошибка — измените режим ответа и сохраните профиль ещё раз.',
            ]);
        }

        return array_merge($state, [
            'status' => 'ready',
            'error' => null,
        ]);
    }
}
