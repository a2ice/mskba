<?php

namespace App\Modules\Ai\Infrastructure\Gateways;

use App\Modules\Ai\Application\Contracts\PlayerCharacterAiGateway;
use App\Modules\Ai\Application\Dto\FaceReferenceValidationResult;
use App\Modules\Ai\Application\Dto\GeneratedPlayerCharacterImage;
use App\Modules\Ai\Domain\Exceptions\AiServiceException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use JsonException;

final class OpenAiPlayerCharacterAiGateway implements PlayerCharacterAiGateway
{
    public function validateFaceReferences(array $references): array
    {
        if ($references === []) {
            return [];
        }

        $content = [[
            'type' => 'input_text',
            'text' => $this->faceValidationPrompt(array_keys($references)),
        ]];

        foreach ($references as $slot => $reference) {
            $mime = (string) ($reference['mime'] ?? 'image/webp');
            $image = (string) ($reference['contents'] ?? '');

            if ($image === '') {
                throw new AiServiceException(
                    'face_reference_invalid_file',
                    'Не удалось прочитать фотографию лица.',
                    422,
                );
            }

            $content[] = [
                'type' => 'input_text',
                'text' => 'Expected slot for the next image: '.$slot,
            ];
            $content[] = [
                'type' => 'input_image',
                'image_url' => 'data:'.$mime.';base64,'.base64_encode($image),
                'detail' => 'high',
            ];
        }

        $payload = [
            'model' => $this->validationModel(),
            'input' => [[
                'role' => 'user',
                'content' => $content,
            ]],
            'text' => [
                'format' => [
                    'type' => 'json_schema',
                    'name' => 'player_face_reference_validation',
                    'strict' => true,
                    'schema' => [
                        'type' => 'object',
                        'properties' => [
                            'references' => [
                                'type' => 'array',
                                'items' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'slot' => [
                                            'type' => 'string',
                                            'enum' => ['front', 'left', 'right'],
                                        ],
                                        'valid' => ['type' => 'boolean'],
                                        'detected_slot' => [
                                            'type' => 'string',
                                            'enum' => ['front', 'left', 'right', 'unknown'],
                                        ],
                                        'reason' => ['type' => 'string'],
                                        'skin_tone' => ['type' => 'string'],
                                    ],
                                    'required' => [
                                        'slot',
                                        'valid',
                                        'detected_slot',
                                        'reason',
                                        'skin_tone',
                                    ],
                                    'additionalProperties' => false,
                                ],
                            ],
                        ],
                        'required' => ['references'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
        ];

        $response = $this->postJson(
            '/responses',
            $payload,
            $this->validationTimeout(),
            'face_validation',
        );

        try {
            $decoded = json_decode($this->responseOutputText($response), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            Log::warning('OpenAI face validation returned invalid structured output.', [
                'request_id' => $response->header('x-request-id'),
                'model' => $this->validationModel(),
                'exception' => $exception->getMessage(),
            ]);

            throw new AiServiceException(
                'ai_request_rejected',
                'Сервис AI вернул некорректный результат проверки лица.',
                502,
            );
        }

        $bySlot = [];
        foreach ((array) ($decoded['references'] ?? []) as $item) {
            $slot = (string) ($item['slot'] ?? '');

            if (! array_key_exists($slot, $references)) {
                continue;
            }

            $detectedSlot = (string) ($item['detected_slot'] ?? 'unknown');
            $skinTone = trim((string) ($item['skin_tone'] ?? ''));
            $reason = trim((string) ($item['reason'] ?? ''));

            $bySlot[$slot] = new FaceReferenceValidationResult(
                valid: (bool) ($item['valid'] ?? false),
                detectedSlot: $detectedSlot === 'unknown' ? null : $detectedSlot,
                skinTone: $skinTone !== '' ? $skinTone : null,
                reason: $reason !== '' ? $reason : null,
            );
        }

        foreach (array_keys($references) as $slot) {
            $bySlot[$slot] ??= new FaceReferenceValidationResult(
                valid: false,
                detectedSlot: null,
                skinTone: null,
                reason: 'AI не смог подтвердить этот ракурс.',
            );
        }

        Log::info('OpenAI face references validated.', [
            'request_id' => $response->header('x-request-id'),
            'model' => $this->validationModel(),
            'slots' => array_keys($references),
            'valid_slots' => array_keys(array_filter(
                $bySlot,
                static fn (FaceReferenceValidationResult $result): bool => $result->valid,
            )),
        ]);

        return $bySlot;
    }

    public function generatePlayerCharacter(array $payload): GeneratedPlayerCharacterImage
    {
        $references = (array) ($payload['face_references'] ?? []);

        if ($references === []) {
            throw AiServiceException::generationFailed();
        }

        $request = $this->request($this->generationTimeout());

        foreach ($references as $slot => $reference) {
            $contents = (string) ($reference['contents'] ?? '');
            $mime = (string) ($reference['mime'] ?? 'image/webp');

            if ($contents === '') {
                continue;
            }

            $extension = match ($mime) {
                'image/png' => 'png',
                'image/jpeg' => 'jpg',
                default => 'webp',
            };

            $request = $request->attach(
                'image[]',
                $contents,
                $slot.'.'.$extension,
                ['Content-Type' => $mime],
            );
        }

        try {
            $response = $request->post($this->url('/images/edits'), [
                'model' => $this->imageModel(),
                'prompt' => $this->generationPrompt($payload),
                'size' => (string) config('services.openai.image_size', '1024x1536'),
                'quality' => (string) config('services.openai.image_quality', 'medium'),
                'background' => 'transparent',
                'output_format' => 'png',
                'moderation' => 'auto',
            ]);
        } catch (ConnectionException $exception) {
            $this->throwConnectionException($exception, 'generation');
        }

        $this->ensureSuccessful($response, 'generation');

        $encoded = data_get($response->json(), 'data.0.b64_json');
        $contents = is_string($encoded) ? base64_decode($encoded, true) : false;

        if (! is_string($contents) || $contents === '') {
            Log::warning('OpenAI image generation returned no decodable image.', [
                'request_id' => $response->header('x-request-id'),
                'model' => $this->imageModel(),
            ]);

            throw AiServiceException::generationFailed();
        }

        $imageInfo = @getimagesizefromstring($contents);
        if (! is_array($imageInfo) || ($imageInfo['mime'] ?? null) !== 'image/png') {
            throw new AiServiceException(
                'generation_failed',
                'AI вернул изображение в неподдерживаемом формате.',
                502,
            );
        }

        if (! $this->hasTransparentBackground($contents)) {
            Log::warning('OpenAI image generation did not return a transparent background.', [
                'request_id' => $response->header('x-request-id'),
                'model' => $this->imageModel(),
            ]);

            throw new AiServiceException(
                'generation_failed',
                'Не удалось получить модель с прозрачным фоном. Попробуйте ещё раз.',
                502,
            );
        }

        Log::info('OpenAI player character generated.', [
            'request_id' => $response->header('x-request-id'),
            'model' => $this->imageModel(),
            'size' => (string) config('services.openai.image_size', '1024x1536'),
            'quality' => (string) config('services.openai.image_quality', 'medium'),
            'bytes' => strlen($contents),
        ]);

        return new GeneratedPlayerCharacterImage($contents, 'image/png');
    }

    private function faceValidationPrompt(array $slots): string
    {
        return <<<'PROMPT'
You validate face reference photos for a basketball-player character generator.

The caller will provide images, each preceded by its expected slot: front, left, or right.

Definitions:
- front: a clear frontal face, looking approximately toward the camera.
- left: the SUBJECT'S left facial profile is shown; the camera is on the subject's left side and the nose points toward the image's right.
- right: the SUBJECT'S right facial profile is shown; the camera is on the subject's right side and the nose points toward the image's left.

A photo is valid only when:
- exactly one usable human face is clearly visible;
- the face is sufficiently large and not heavily obscured;
- the orientation matches the expected slot;
- the image is suitable as an identity reference for a realistic full-body character.

Do not identify the person. Do not infer sensitive traits.
For skin_tone return only a neutral visual color hint as a hex RGB value like #C58F6A, based on visible pixels; return an empty string when it cannot be estimated reliably.
For a valid photo, reason should be an empty string.
For an invalid photo, give a short Russian user-facing reason.
Return one result for every supplied slot.
PROMPT;
    }

    private function generationPrompt(array $payload): string
    {
        $appearance = (array) ($payload['appearance'] ?? []);
        $team = is_array($payload['team'] ?? null) ? $payload['team'] : null;

        $description = [
            'gender' => $payload['gender'] ?? null,
            'height_cm' => $payload['height_cm'] ?? null,
            'weight_kg' => $payload['weight_kg'] ?? null,
            'body_type' => $payload['body_type'] ?? null,
            'shoes' => $appearance['shoes'] ?? 'white',
            'attributes' => array_values((array) ($appearance['attributes'] ?? [])),
            'chest_volume' => $appearance['chest_volume'] ?? null,
            'team' => $team ? [
                'name' => $team['name'] ?? null,
                'colors' => $team['colors'] ?? null,
            ] : null,
        ];

        $json = json_encode(
            $description,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT,
        );

        return <<<PROMPT
Create ONE photorealistic full-body basketball player using the supplied face-reference images as identity references.

Identity:
- Preserve the same person's facial identity from the reference images as closely as possible.
- Use the reference images for identity only; do not copy their background, crop, lighting, clothing, or camera angle.

Composition:
- One person only.
- Full body from head to both feet, completely inside frame with comfortable transparent padding.
- Standing upright, front-facing, neutral athletic stance, arms relaxed and slightly away from the torso.
- Camera at approximately waist/chest height, natural perspective, no dramatic foreshortening.
- Realistic anatomy and proportions appropriate for the requested height, weight, body type, and gender.
- Basketball-player physique, not a bodybuilder caricature.

Clothing:
- Modern basketball jersey and shorts.
- If team colors are supplied, use those colors as the uniform palette.
- Do not invent brand logos, sponsors, text, names, numbers, watermarks, or badges.
- Apply requested shoes and sports attributes naturally.

OUTPUT REQUIREMENTS — STRICT:
- Background MUST be fully transparent alpha, not white, black, gray, checkerboard, studio, floor, gradient, or scenery.
- No floor plane and no cast shadow outside the player's silhouette.
- PNG with transparency.
- The player must be isolated and ready to place directly over the MSKBA height scale.

Character parameters:
{$json}
PROMPT;
    }

    private function postJson(string $path, array $payload, int $timeout, string $operation): Response
    {
        try {
            $response = $this->request($timeout)->post($this->url($path), $payload);
        } catch (ConnectionException $exception) {
            $this->throwConnectionException($exception, $operation);
        }

        $this->ensureSuccessful($response, $operation);

        return $response;
    }

    private function request(int $timeout): PendingRequest
    {
        return Http::withToken($this->apiKey())
            ->acceptJson()
            ->connectTimeout((int) config('services.openai.connect_timeout_seconds', 10))
            ->timeout($timeout);
    }

    private function ensureSuccessful(Response $response, string $operation): void
    {
        if ($response->successful()) {
            return;
        }

        $status = $response->status();
        $providerCode = data_get($response->json(), 'error.code');
        $providerType = data_get($response->json(), 'error.type');
        $requestId = $response->header('x-request-id');

        Log::warning('OpenAI player-character request failed.', [
            'operation' => $operation,
            'status' => $status,
            'provider_code' => $providerCode,
            'provider_type' => $providerType,
            'request_id' => $requestId,
        ]);

        $context = [
            'provider' => 'openai',
            'provider_request_id' => $requestId,
            'provider_code' => $providerCode,
        ];

        if ($status === 401) {
            if ($providerCode === 'ip_not_authorized') {
                throw new AiServiceException(
                    'ai_ip_not_authorized',
                    'IP сервера не разрешён в настройках OpenAI API.',
                    503,
                    $context,
                );
            }

            throw new AiServiceException(
                'ai_authentication_failed',
                'Ключ OpenAI API отклонён. Создайте новый ключ и обновите секрет OPENAI_API_KEY.',
                503,
                $context,
            );
        }

        if ($status === 403) {
            if ($providerCode === 'unsupported_country_region_territory') {
                throw new AiServiceException(
                    'ai_unsupported_region',
                    'OpenAI API недоступен из региона, где расположен сервер MSKBA.',
                    503,
                    $context,
                );
            }

            throw new AiServiceException(
                'ai_permission_denied',
                'У ключа OpenAI API нет доступа к этому ресурсу. Проверьте права ключа и проекта.',
                503,
                $context,
            );
        }

        if ($status === 429) {
            if ($providerCode === 'insufficient_quota') {
                throw new AiServiceException(
                    'ai_quota_exceeded',
                    'Лимит API генерации исчерпан. Попробуйте позже.',
                    503,
                    $context,
                );
            }

            throw new AiServiceException(
                'ai_rate_limited',
                'Сервис AI временно перегружен. Попробуйте ещё раз позже.',
                429,
                $context,
            );
        }

        if (in_array($status, [408, 504], true)) {
            throw new AiServiceException(
                'generation_timeout',
                'Сервис AI не ответил вовремя. Попробуйте ещё раз.',
                504,
                $context,
            );
        }

        if ($status >= 500) {
            throw new AiServiceException(
                'ai_connection_failed',
                'Сервис AI временно недоступен. Попробуйте ещё раз позже.',
                503,
                $context,
            );
        }

        throw new AiServiceException(
            'ai_request_rejected',
            'Сервис AI отклонил запрос.',
            422,
            $context,
        );
    }

    private function throwConnectionException(ConnectionException $exception, string $operation): never
    {
        $message = mb_strtolower($exception->getMessage());
        $timeout = str_contains($message, 'timed out') || str_contains($message, 'timeout');

        Log::warning('OpenAI player-character connection failed.', [
            'operation' => $operation,
            'timeout' => $timeout,
            'exception' => $exception->getMessage(),
        ]);

        if ($timeout) {
            throw AiServiceException::timeout();
        }

        throw AiServiceException::connectionFailed();
    }

    private function responseOutputText(Response $response): string
    {
        $json = $response->json();

        if (is_string($json['output_text'] ?? null) && $json['output_text'] !== '') {
            return $json['output_text'];
        }

        foreach ((array) ($json['output'] ?? []) as $item) {
            foreach ((array) ($item['content'] ?? []) as $content) {
                if (($content['type'] ?? null) === 'output_text' && is_string($content['text'] ?? null)) {
                    return $content['text'];
                }
            }
        }

        throw new AiServiceException(
            'ai_request_rejected',
            'Сервис AI не вернул результат проверки лица.',
            502,
        );
    }

    private function hasTransparentBackground(string $contents): bool
    {
        $image = @imagecreatefromstring($contents);

        if ($image === false) {
            return false;
        }

        $width = imagesx($image);
        $height = imagesy($image);

        $points = [
            [0, 0],
            [max(0, $width - 1), 0],
            [0, max(0, $height - 1)],
            [max(0, $width - 1), max(0, $height - 1)],
        ];

        foreach ($points as [$x, $y]) {
            $rgba = imagecolorat($image, $x, $y);
            $alpha = ($rgba & 0x7F000000) >> 24;

            if ($alpha < 100) {
                imagedestroy($image);

                return false;
            }
        }

        imagedestroy($image);

        return true;
    }

    private function apiKey(): string
    {
        $key = trim((string) config('services.openai.api_key'));

        if ($key === '') {
            throw AiServiceException::notConfigured();
        }

        return $key;
    }

    private function validationModel(): string
    {
        return (string) config('services.openai.face_validation_model', 'gpt-5.6-luna');
    }

    private function imageModel(): string
    {
        return (string) config('services.openai.image_model', 'gpt-image-2');
    }

    private function validationTimeout(): int
    {
        return max(5, (int) config('services.openai.validation_timeout_seconds', 45));
    }

    private function generationTimeout(): int
    {
        return max(30, (int) config('services.openai.generation_timeout_seconds', 180));
    }

    private function url(string $path): string
    {
        return rtrim((string) config('services.openai.base_url', 'https://api.openai.com/v1'), '/')
            .'/'.ltrim($path, '/');
    }
}
