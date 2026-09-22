<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

final class SmokeYandexAiCommand extends Command
{
    protected $signature = 'ai:yandex:smoke {--multimodal : Also test the real face-validation response shape with a synthetic image}';

    protected $description = 'Проверить production-доступ к Yandex AI Studio без вывода API key';

    public function handle(): int
    {
        $apiKey = trim((string) config('services.yandex_ai.api_key'));
        $folderId = trim((string) config('services.yandex_ai.folder_id'));
        $baseUrl = rtrim((string) config('services.yandex_ai.base_url', 'https://ai.api.cloud.yandex.net/v1'), '/');
        $model = trim((string) config('services.yandex_ai.face_validation_model', 'qwen3.6-35b-a3b'));

        if ($apiKey === '' || $folderId === '') {
            $this->error('Yandex AI diagnostic: api key or folder id is not configured.');

            return self::FAILURE;
        }

        $this->line('Yandex AI diagnostic key fingerprint: '.substr(hash('sha256', $apiKey), 0, 12));
        $this->line('Yandex AI diagnostic folder: '.$folderId);
        $this->line('Yandex AI diagnostic model: '.$model);

        try {
            $request = $this->request($apiKey, $folderId);
            $modelUri = str_starts_with($model, 'gpt://')
                ? $model
                : 'gpt://'.$folderId.'/'.ltrim($model, '/');

            $textResponse = $request->post($baseUrl.'/responses', [
                'model' => $modelUri,
                'input' => 'Reply with exactly OK.',
                'max_output_tokens' => 8,
            ]);

            $this->report('text', $textResponse, false);

            if (! $this->option('multimodal')) {
                return $textResponse->successful() ? self::SUCCESS : self::FAILURE;
            }

            $multimodalResponse = $request->post($baseUrl.'/responses', [
                'model' => $modelUri,
                'input' => [[
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'input_text',
                            'text' => 'This is a synthetic diagnostic image. Return exactly one result for slot front. Do not identify anyone.',
                        ],
                        [
                            'type' => 'input_text',
                            'text' => 'Expected slot for the next image: front',
                        ],
                        [
                            'type' => 'input_image',
                            'image_url' => 'data:image/png;base64,'.base64_encode($this->syntheticFacePng()),
                            'detail' => 'high',
                        ],
                    ],
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
            ]);

            $this->report('multimodal_structured', $multimodalResponse, true);

            return $textResponse->successful() && $multimodalResponse->successful()
                ? self::SUCCESS
                : self::FAILURE;
        } catch (ConnectionException $exception) {
            $this->error('Yandex AI diagnostic connection failure: '.$exception->getMessage());

            return self::FAILURE;
        } catch (Throwable $exception) {
            $this->error('Yandex AI diagnostic unexpected failure: '.$exception::class.': '.$exception->getMessage());

            return self::FAILURE;
        }
    }

    private function request(string $apiKey, string $folderId): PendingRequest
    {
        return Http::withHeaders([
            'Authorization' => 'Api-Key '.$apiKey,
            'OpenAI-Project' => $folderId,
        ])
            ->acceptJson()
            ->connectTimeout(10)
            ->timeout(45);
    }

    private function report(string $operation, Response $response, bool $includeSafeShape): void
    {
        $json = $response->json();
        $requestId = $response->header('x-request-id')
            ?: $response->header('x-server-trace-id')
            ?: '-';

        $providerCode = data_get($json, 'error.code')
            ?? data_get($json, 'code')
            ?? '-';
        $providerType = data_get($json, 'error.type')
            ?? data_get($json, 'type')
            ?? '-';
        $providerMessage = data_get($json, 'error.message')
            ?? data_get($json, 'message')
            ?? '-';

        $this->line(sprintf(
            'Yandex AI diagnostic [%s] status=%d request_id=%s code=%s type=%s message=%s',
            $operation,
            $response->status(),
            $requestId,
            is_scalar($providerCode) ? (string) $providerCode : '-',
            is_scalar($providerType) ? (string) $providerType : '-',
            is_scalar($providerMessage)
                ? mb_substr(preg_replace('/\s+/', ' ', (string) $providerMessage) ?: '', 0, 500)
                : '-',
        ));

        if (! $includeSafeShape || ! is_array($json)) {
            return;
        }

        $summary = [
            'status' => $json['status'] ?? null,
            'top_level_keys' => array_keys($json),
            'output_text' => is_scalar($json['output_text'] ?? null)
                ? mb_substr((string) $json['output_text'], 0, 1000)
                : null,
            'output' => [],
            'incomplete_details' => $json['incomplete_details'] ?? null,
            'error' => $json['error'] ?? null,
        ];

        foreach ((array) ($json['output'] ?? []) as $item) {
            $itemSummary = [
                'type' => $item['type'] ?? null,
                'status' => $item['status'] ?? null,
                'role' => $item['role'] ?? null,
                'text' => is_scalar($item['text'] ?? null)
                    ? mb_substr((string) $item['text'], 0, 1000)
                    : null,
                'content' => [],
            ];

            foreach ((array) ($item['content'] ?? []) as $content) {
                $itemSummary['content'][] = [
                    'type' => $content['type'] ?? null,
                    'text' => is_scalar($content['text'] ?? null)
                        ? mb_substr((string) $content['text'], 0, 1000)
                        : null,
                    'refusal' => is_scalar($content['refusal'] ?? null)
                        ? mb_substr((string) $content['refusal'], 0, 1000)
                        : null,
                ];
            }

            $summary['output'][] = $itemSummary;
        }

        $this->line('Yandex AI diagnostic safe response shape: '.json_encode(
            $summary,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        ));
    }

    private function syntheticFacePng(): string
    {
        $image = imagecreatetruecolor(96, 96);
        imagealphablending($image, true);
        imagesavealpha($image, true);

        $background = imagecolorallocate($image, 245, 245, 245);
        $skin = imagecolorallocate($image, 205, 165, 125);
        $dark = imagecolorallocate($image, 30, 30, 30);

        imagefill($image, 0, 0, $background);
        imagefilledellipse($image, 48, 48, 60, 72, $skin);
        imagefilledellipse($image, 37, 42, 6, 6, $dark);
        imagefilledellipse($image, 59, 42, 6, 6, $dark);
        imageline($image, 43, 62, 53, 62, $dark);

        ob_start();
        imagepng($image);
        $contents = ob_get_clean();
        imagedestroy($image);

        if (! is_string($contents) || $contents === '') {
            throw new \RuntimeException('Unable to create synthetic diagnostic PNG.');
        }

        return $contents;
    }
}
