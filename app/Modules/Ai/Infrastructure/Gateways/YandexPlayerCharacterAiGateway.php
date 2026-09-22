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

final class YandexPlayerCharacterAiGateway implements PlayerCharacterAiGateway
{
    public function validateFaceReferences(array $references): array
    {
        if ($references === []) {
            return [];
        }

        $content = [[
            'type' => 'input_text',
            'text' => $this->faceValidationPrompt(),
        ]];

        foreach ($references as $slot => $reference) {
            $image = (string) ($reference['contents'] ?? '');
            $mime = (string) ($reference['mime'] ?? 'image/webp');

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

        $response = $this->postResponses([
            'model' => $this->modelUri($this->validationModel()),
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
        ], $this->validationTimeout(), 'face_validation');

        try {
            $decoded = json_decode($this->responseOutputText($response), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            Log::warning('Yandex AI face validation returned invalid structured output.', [
                'request_id' => $this->requestId($response),
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
                reason: 'AI не смог подтвердить этот ракурс.',
            );
        }

        Log::info('Yandex AI face references validated.', [
            'request_id' => $this->requestId($response),
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

        $content = [[
            'type' => 'input_text',
            'text' => $this->generationPrompt($payload),
        ]];

        foreach ($references as $slot => $reference) {
            $image = (string) ($reference['contents'] ?? '');
            $mime = (string) ($reference['mime'] ?? 'image/webp');

            if ($image === '') {
                continue;
            }

            $content[] = [
                'type' => 'input_text',
                'text' => 'Identity reference '.$slot.':',
            ];
            $content[] = [
                'type' => 'input_image',
                'image_url' => 'data:'.$mime.';base64,'.base64_encode($image),
                'detail' => 'high',
            ];
        }

        $response = $this->postResponses([
            'model' => $this->modelUri($this->generationModel()),
            'input' => [[
                'role' => 'user',
                'content' => $content,
            ]],
            'tools' => [[
                'type' => 'image_generation',
                'model' => $this->imageModel(),
                'size' => (string) config('services.yandex_ai.image_size', '1024x1536'),
                'quality' => (string) config('services.yandex_ai.image_quality', 'high'),
                'output_format' => 'png',
                'input_fidelity' => 'high',
                'action' => 'auto',
            ]],
        ], $this->generationTimeout(), 'generation');

        $encoded = null;
        foreach ((array) $response->json('output', []) as $item) {
            if (
                ($item['type'] ?? null) === 'image_generation_call'
                && ($item['status'] ?? null) === 'completed'
                && is_string($item['result'] ?? null)
            ) {
                $encoded = $item['result'];
                break;
            }
        }

        $contents = is_string($encoded) ? base64_decode($encoded, true) : false;

        if (! is_string($contents) || $contents === '') {
            Log::warning('Yandex AI image generation returned no decodable image.', [
                'request_id' => $this->requestId($response),
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
            Log::warning('Yandex AI image generation did not return a transparent background.', [
                'request_id' => $this->requestId($response),
                'model' => $this->imageModel(),
            ]);

            throw new AiServiceException(
                'generation_background_not_transparent',
                'Яндекс сгенерировал персонажа без прозрачного фона. Этот режим пока тестовый.',
                502,
                [
                    'provider' => 'yandex',
                    'provider_request_id' => $this->requestId($response),
                ],
            );
        }

        Log::info('Yandex AI player character generated.', [
            'request_id' => $this->requestId($response),
            'model' => $this->imageModel(),
            'size' => (string) config('services.yandex_ai.image_size', '1024x1536'),
            'quality' => (string) config('services.yandex_ai.image_quality', 'high'),
            'bytes' => strlen($contents),
        ]);

        return new GeneratedPlayerCharacterImage($contents, 'image/png');
    }

    private function postResponses(array $payload, int $timeout, string $operation): Response
    {
        try {
            $response = $this->request($timeout)->post($this->url('/responses'), $payload);
        } catch (ConnectionException $exception) {
            $this->throwConnectionException($exception, $operation);
        }

        $this->ensureSuccessful($response, $operation);

        return $response;
    }

    private function request(int $timeout): PendingRequest
    {
        return Http::withHeaders([
            'Authorization' => 'Api-Key '.$this->apiKey(),
            'OpenAI-Project' => $this->folderId(),
        ])
            ->acceptJson()
            ->connectTimeout((int) config('services.yandex_ai.connect_timeout_seconds', 10))
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
        $requestId = $this->requestId($response);

        Log::warning('Yandex AI player-character request failed.', [
            'operation' => $operation,
            'status' => $status,
            'provider_code' => $providerCode,
            'provider_type' => $providerType,
            'request_id' => $requestId,
        ]);

        $context = [
            'provider' => 'yandex',
            'provider_request_id' => $requestId,
            'provider_code' => $providerCode,
        ];

        if (in_array($status, [401, 403], true)) {
            throw new AiServiceException(
                'ai_authentication_failed',
                'Яндекс AI отклонил ключ или у сервисного аккаунта недостаточно прав.',
                503,
                $context,
            );
        }

        if ($status === 429) {
            throw new AiServiceException(
                'ai_rate_limited',
                'Яндекс AI временно ограничил количество запросов. Попробуйте позже.',
                429,
                $context,
            );
        }

        if (in_array($status, [408, 504], true)) {
            throw AiServiceException::timeout();
        }

        if ($status >= 500) {
            throw AiServiceException::connectionFailed();
        }

        throw new AiServiceException(
            'ai_request_rejected',
            'Яндекс AI отклонил запрос.',
            422,
            $context,
        );
    }

    private function throwConnectionException(ConnectionException $exception, string $operation): never
    {
        $message = mb_strtolower($exception->getMessage());
        $timeout = str_contains($message, 'timed out') || str_contains($message, 'timeout');

        Log::warning('Yandex AI player-character connection failed.', [
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
            'Яндекс AI не вернул результат проверки лица.',
            502,
        );
    }

    private function faceValidationPrompt(): string
    {
        return <<<'PROMPT'
You validate face reference photos for a basketball-player character generator.

The caller provides images preceded by their expected slot: front, left, or right.

Definitions:
- front: clear frontal face, looking approximately toward the camera.
- left: the SUBJECT'S left facial profile; the nose points toward the image's right.
- right: the SUBJECT'S right facial profile; the nose points toward the image's left.

A photo is valid only when exactly one usable human face is clearly visible, sufficiently large,
not heavily obscured, and the orientation matches the expected slot.

Do not identify the person and do not infer sensitive traits.
For skin_tone return only a neutral visual hex RGB hint based on visible pixels, or an empty string.
For a valid photo reason must be empty. For an invalid photo provide a short Russian user-facing reason.
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
Create one photorealistic full-body basketball player. The attached images are identity references
for the SAME person. Preserve that person's facial identity as closely as possible. Do not reproduce
the source backgrounds, crops, lighting, or clothing.

The player must be completely visible head-to-toe, front-facing, standing upright in a neutral
athletic pose. Use realistic anatomy and proportions matching the requested gender, height, weight
and body type. Use a modern basketball jersey and shorts, requested team colors, shoes and sports
attributes. Do not add logos, sponsors, names, numbers, text, watermarks, extra people or extra limbs.

IMPORTANT OUTPUT:
- Generate a PNG.
- Isolate the player on a genuinely transparent alpha background if the image tool supports it.
- No scenery, floor, studio background or checkerboard pattern.
- Keep comfortable transparent padding around the whole body.

Character parameters:
{$json}
PROMPT;
    }

    private function hasTransparentBackground(string $contents): bool
    {
        $image = @imagecreatefromstring($contents);

        if ($image === false) {
            return false;
        }

        $width = imagesx($image);
        $height = imagesy($image);

        foreach ([
            [0, 0],
            [max(0, $width - 1), 0],
            [0, max(0, $height - 1)],
            [max(0, $width - 1), max(0, $height - 1)],
        ] as [$x, $y]) {
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

    private function modelUri(string $model): string
    {
        return str_starts_with($model, 'gpt://')
            ? $model
            : 'gpt://'.$this->folderId().'/'.ltrim($model, '/');
    }

    private function apiKey(): string
    {
        $key = trim((string) config('services.yandex_ai.api_key'));

        if ($key === '') {
            throw AiServiceException::notConfigured();
        }

        return $key;
    }

    private function folderId(): string
    {
        $folderId = trim((string) config('services.yandex_ai.folder_id'));

        if ($folderId === '') {
            throw AiServiceException::notConfigured();
        }

        return $folderId;
    }

    private function validationModel(): string
    {
        return (string) config('services.yandex_ai.face_validation_model', 'qwen3.6-35b-a3b');
    }

    private function generationModel(): string
    {
        return (string) config('services.yandex_ai.generation_model', 'qwen3.6-35b-a3b');
    }

    private function imageModel(): string
    {
        return (string) config('services.yandex_ai.image_model', 'aliceai-image-art-3.0');
    }

    private function validationTimeout(): int
    {
        return max(5, (int) config('services.yandex_ai.validation_timeout_seconds', 45));
    }

    private function generationTimeout(): int
    {
        return max(30, (int) config('services.yandex_ai.generation_timeout_seconds', 180));
    }

    private function requestId(Response $response): ?string
    {
        return $response->header('x-request-id')
            ?: $response->header('x-server-trace-id');
    }

    private function url(string $path): string
    {
        return rtrim((string) config('services.yandex_ai.base_url', 'https://ai.api.cloud.yandex.net/v1'), '/')
            .'/'.ltrim($path, '/');
    }
}
