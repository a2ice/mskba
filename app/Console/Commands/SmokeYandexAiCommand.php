<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

final class SmokeYandexAiCommand extends Command
{
    protected $signature = 'ai:yandex:smoke';

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
            $response = Http::withHeaders([
                'Authorization' => 'Api-Key '.$apiKey,
                'OpenAI-Project' => $folderId,
            ])
                ->acceptJson()
                ->connectTimeout(10)
                ->timeout(30)
                ->post($baseUrl.'/responses', [
                    'model' => str_starts_with($model, 'gpt://')
                        ? $model
                        : 'gpt://'.$folderId.'/'.ltrim($model, '/'),
                    'input' => 'Reply with exactly OK.',
                    'max_output_tokens' => 8,
                ]);

            $requestId = $response->header('x-request-id')
                ?: $response->header('x-server-trace-id')
                ?: '-';

            $providerCode = data_get($response->json(), 'error.code')
                ?? data_get($response->json(), 'code')
                ?? '-';
            $providerType = data_get($response->json(), 'error.type')
                ?? data_get($response->json(), 'type')
                ?? '-';
            $providerMessage = data_get($response->json(), 'error.message')
                ?? data_get($response->json(), 'message')
                ?? '-';

            $this->line(sprintf(
                'Yandex AI diagnostic status=%d request_id=%s code=%s type=%s message=%s',
                $response->status(),
                $requestId,
                is_scalar($providerCode) ? (string) $providerCode : '-',
                is_scalar($providerType) ? (string) $providerType : '-',
                is_scalar($providerMessage)
                    ? mb_substr(preg_replace('/\s+/', ' ', (string) $providerMessage) ?: '', 0, 500)
                    : '-',
            ));

            return $response->successful() ? self::SUCCESS : self::FAILURE;
        } catch (ConnectionException $exception) {
            $this->error('Yandex AI diagnostic connection failure: '.$exception->getMessage());

            return self::FAILURE;
        } catch (Throwable $exception) {
            $this->error('Yandex AI diagnostic unexpected failure: '.$exception::class.': '.$exception->getMessage());

            return self::FAILURE;
        }
    }
}
