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
                'image_url' => $this->inputImageDataUrl($image),
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

            if ($image === '') {
                continue;
            }

            $content[] = [
                'type' => 'input_text',
                'text' => 'Identity reference '.$slot.':',
            ];
            $content[] = [
                'type' => 'input_image',
                'image_url' => $this->inputImageDataUrl($image),
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
                'size' => (string) config('services.yandex_ai.image_size', '1024x1536'),
                'quality' => (string) config('services.yandex_ai.image_quality', 'high'),
                'input_fidelity' => 'high',
                'action' => 'generate',
            ]],
            'parallel_tool_calls' => false,
            'max_tool_calls' => 1,
        ], $this->generationTimeout(), 'generation');

        $encoded = null;
        $imageCallCount = 0;

        foreach ((array) $response->json('output', []) as $item) {
            if (($item['type'] ?? null) !== 'image_generation_call') {
                continue;
            }

            $imageCallCount++;

            if (
                $encoded === null
                && ($item['status'] ?? null) === 'completed'
                && is_string($item['result'] ?? null)
                && $item['result'] !== ''
            ) {
                // Yandex may emit more than one image tool call even when
                // max_tool_calls=1. The first completed image is the product
                // result; later calls are intentionally ignored.
                $encoded = $item['result'];
            }
        }

        $contents = is_string($encoded) ? base64_decode($encoded, true) : false;

        if (! is_string($contents) || $contents === '') {
            Log::warning('Yandex AI image generation returned no decodable image.', [
                'request_id' => $this->requestId($response),
                'generation_model' => $this->generationModel(),
                'image_call_count' => $imageCallCount,
            ]);

            throw AiServiceException::generationFailed();
        }

        $imageInfo = @getimagesizefromstring($contents);
        $mime = is_array($imageInfo) && is_string($imageInfo['mime'] ?? null)
            ? $imageInfo['mime']
            : null;

        if ($mime === null || ! str_starts_with($mime, 'image/')) {
            throw new AiServiceException(
                'generation_failed',
                'AI вернул изображение в неподдерживаемом формате.',
                502,
            );
        }

        Log::info('Yandex AI player character generated.', [
            'request_id' => $this->requestId($response),
            'generation_model' => $this->generationModel(),
            'requested_size' => (string) config('services.yandex_ai.image_size', '1024x1536'),
            'quality' => (string) config('services.yandex_ai.image_quality', 'high'),
            'actual_mime' => $mime,
            'actual_width' => $imageInfo[0] ?? null,
            'actual_height' => $imageInfo[1] ?? null,
            'image_call_count' => $imageCallCount,
            'bytes' => strlen($contents),
        ]);

        return new GeneratedPlayerCharacterImage($contents, $mime);
    }

    private function postResponses(array $payload, int $timeout, string $operation): Response
    {
        $startedAt = microtime(true);

        try {
            $response = $this->request($timeout)->post($this->url('/responses'), $payload);
        } catch (ConnectionException $exception) {
            $this->throwConnectionException($exception, $operation);
        }

        $this->ensureSuccessful($response, $operation);

        return $this->waitForResponseCompletion($response, $timeout, $operation, $startedAt);
    }

    private function waitForResponseCompletion(
        Response $response,
        int $timeout,
        string $operation,
        float $startedAt,
    ): Response {
        $status = mb_strtolower(trim((string) $response->json('status', '')));

        if (! in_array($status, ['queued', 'in_progress'], true)) {
            $this->ensureCompletedResponseState($response, $operation);

            return $response;
        }

        $responseId = trim((string) $response->json('id', ''));

        if ($responseId === '') {
            Log::warning('Yandex AI returned a pending response without an id.', [
                'operation' => $operation,
                'status' => $status,
                'request_id' => $this->requestId($response),
            ]);

            throw AiServiceException::generationFailed();
        }

        $pollCount = 0;

        while (in_array($status, ['queued', 'in_progress'], true)) {
            $remaining = $timeout - (microtime(true) - $startedAt);

            if ($remaining <= 0) {
                Log::warning('Yandex AI response polling timed out.', [
                    'operation' => $operation,
                    'response_id' => $responseId,
                    'status' => $status,
                    'poll_count' => $pollCount,
                ]);

                throw AiServiceException::timeout();
            }

            $pollIntervalMs = $this->responsePollIntervalMs();
            if ($pollIntervalMs > 0) {
                usleep((int) min($pollIntervalMs * 1000, max(1, $remaining * 1_000_000)));
            }

            $remaining = max(1, (int) ceil($timeout - (microtime(true) - $startedAt)));

            try {
                $response = $this->request($remaining)->get(
                    $this->url('/responses/'.rawurlencode($responseId)),
                );
            } catch (ConnectionException $exception) {
                $this->throwConnectionException($exception, $operation.'_poll');
            }

            $this->ensureSuccessful($response, $operation.'_poll');

            $pollCount++;
            $status = mb_strtolower(trim((string) $response->json('status', '')));
        }

        Log::info('Yandex AI pending response reached terminal state.', [
            'operation' => $operation,
            'response_id' => $responseId,
            'status' => $status !== '' ? $status : null,
            'poll_count' => $pollCount,
        ]);

        $this->ensureCompletedResponseState($response, $operation);

        return $response;
    }

    private function ensureCompletedResponseState(Response $response, string $operation): void
    {
        $status = mb_strtolower(trim((string) $response->json('status', '')));

        if ($status === '' || $status === 'completed') {
            return;
        }

        if (in_array($status, ['queued', 'in_progress'], true)) {
            return;
        }

        $providerCode = data_get($response->json(), 'error.code');
        $providerMessage = data_get($response->json(), 'error.message');
        $incompleteReason = data_get($response->json(), 'incomplete_details.reason');

        Log::warning('Yandex AI response finished without completion.', [
            'operation' => $operation,
            'status' => $status,
            'response_id' => $response->json('id'),
            'request_id' => $this->requestId($response),
            'provider_code' => is_scalar($providerCode) ? (string) $providerCode : null,
            'provider_message' => is_scalar($providerMessage)
                ? $this->sanitizeProviderMessage((string) $providerMessage)
                : null,
            'incomplete_reason' => is_scalar($incompleteReason) ? (string) $incompleteReason : null,
        ]);

        if ((string) $providerCode === 'rate_limit_exceeded') {
            throw AiServiceException::rateLimited();
        }

        if ($operation === 'face_validation') {
            throw new AiServiceException(
                'ai_request_rejected',
                'Яндекс AI не завершил проверку фотографий.',
                502,
                [
                    'provider' => 'yandex',
                    'provider_response_id' => $response->json('id'),
                    'provider_code' => is_scalar($providerCode) ? (string) $providerCode : null,
                ],
            );
        }

        throw new AiServiceException(
            'generation_failed',
            'Яндекс AI не завершил генерацию изображения.',
            502,
            [
                'provider' => 'yandex',
                'provider_response_id' => $response->json('id'),
                'provider_code' => is_scalar($providerCode) ? (string) $providerCode : null,
            ],
        );
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

        $outputText = $json['output_text'] ?? null;
        if (is_string($outputText) && trim($outputText) !== '') {
            return $outputText;
        }

        $output = (array) ($json['output'] ?? []);

        // Yandex documents OpenAI-compatible Responses output, but the raw wire
        // shape is not always accompanied by the convenience top-level
        // output_text aggregate. Prefer canonical output_text content first.
        foreach ($output as $item) {
            foreach ((array) ($item['content'] ?? []) as $content) {
                if (
                    ($content['type'] ?? null) === 'output_text'
                    && is_string($content['text'] ?? null)
                    && trim($content['text']) !== ''
                ) {
                    return $content['text'];
                }
            }
        }

        // Some Yandex Responses variants return message content as type=text
        // (or omit the type) while keeping the same content[].text field.
        foreach ($output as $item) {
            if (is_string($item['text'] ?? null) && trim($item['text']) !== '') {
                return $item['text'];
            }

            foreach ((array) ($item['content'] ?? []) as $content) {
                $type = $content['type'] ?? null;
                $text = $content['text'] ?? null;

                if (
                    in_array($type, [null, 'text'], true)
                    && is_string($text)
                    && trim($text) !== ''
                ) {
                    return $text;
                }
            }
        }

        // Defensive fallback for compatible gateways that expose chat-like
        // choices while accepting the Responses endpoint.
        $choiceContent = data_get($json, 'choices.0.message.content');
        if (is_string($choiceContent) && trim($choiceContent) !== '') {
            return $choiceContent;
        }

        $outputTypes = [];
        $contentTypes = [];

        foreach ($output as $item) {
            $outputTypes[] = $item['type'] ?? null;

            foreach ((array) ($item['content'] ?? []) as $content) {
                $contentTypes[] = $content['type'] ?? null;
            }
        }

        $providerCode = data_get($json, 'error.code');
        $providerType = data_get($json, 'error.type');
        $providerMessage = data_get($json, 'error.message');

        Log::warning('Yandex AI response contained no readable output text.', [
            'request_id' => $this->requestId($response),
            'status' => $json['status'] ?? null,
            'top_level_keys' => array_keys(is_array($json) ? $json : []),
            'output_types' => array_values(array_unique($outputTypes, SORT_REGULAR)),
            'content_types' => array_values(array_unique($contentTypes, SORT_REGULAR)),
            'provider_code' => is_scalar($providerCode) ? (string) $providerCode : null,
            'provider_type' => is_scalar($providerType) ? (string) $providerType : null,
            'provider_message' => is_scalar($providerMessage)
                ? $this->sanitizeProviderMessage((string) $providerMessage)
                : null,
        ]);

        $context = [
            'provider' => 'yandex',
            'provider_request_id' => $this->requestId($response),
            'provider_code' => is_scalar($providerCode) ? (string) $providerCode : null,
        ];

        if (in_array((string) $providerCode, [
            'invalid_image',
            'invalid_image_format',
            'invalid_base64_image',
            'invalid_image_url',
            'image_too_large',
            'image_too_small',
            'image_parse_error',
            'invalid_image_mode',
            'image_file_too_large',
            'unsupported_image_media_type',
            'empty_image_file',
            'failed_to_download_image',
            'image_file_not_found',
        ], true)) {
            throw new AiServiceException(
                'face_reference_invalid_file',
                'Яндекс AI не смог обработать фотографию лица. Попробуйте выбрать другое изображение.',
                422,
                $context,
            );
        }

        throw new AiServiceException(
            'ai_request_rejected',
            'Яндекс AI не вернул результат проверки лица.',
            502,
            $context,
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
- Generate exactly one image.
- Use a flat solid pure green #00FF00 background.
- No scenery, floor, studio set, shadows, gradients or checkerboard pattern.
- Keep comfortable padding around the whole body.

Character parameters:
{$json}
PROMPT;
    }

    private function inputImageDataUrl(string $contents): string
    {
        $image = @imagecreatefromstring($contents);

        if ($image === false) {
            throw new AiServiceException(
                'face_reference_invalid_file',
                'Не удалось подготовить фотографию лица для Яндекс AI.',
                422,
            );
        }

        imagealphablending($image, false);
        imagesavealpha($image, true);

        ob_start();
        $encoded = imagepng($image);
        $png = ob_get_clean();
        imagedestroy($image);

        if (! $encoded || ! is_string($png) || $png === '') {
            throw new AiServiceException(
                'face_reference_invalid_file',
                'Не удалось подготовить фотографию лица для Яндекс AI.',
                422,
            );
        }

        return 'data:image/png;base64,'.base64_encode($png);
    }

    private function sanitizeProviderMessage(string $message): string
    {
        $message = preg_replace(
            '/data:image\\/[^;]+;base64,[A-Za-z0-9+\\/=]+/',
            '[redacted-data-image]',
            $message,
        ) ?: $message;

        return mb_substr(preg_replace('/\\s+/', ' ', $message) ?: '', 0, 500);
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

    private function validationTimeout(): int
    {
        return max(5, (int) config('services.yandex_ai.validation_timeout_seconds', 45));
    }

    private function generationTimeout(): int
    {
        return max(30, (int) config('services.yandex_ai.generation_timeout_seconds', 180));
    }

    private function responsePollIntervalMs(): int
    {
        return max(0, (int) config('services.yandex_ai.response_poll_interval_ms', 1000));
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
