<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

final class SmokeOpenAiCommand extends Command
{
    protected $signature = 'ai:openai:smoke';

    protected $description = 'Проверить production OpenAI credential/model access без вывода API key';

    public function handle(): int
    {
        $key = trim((string) config('services.openai.api_key'));
        $baseUrl = rtrim((string) config('services.openai.base_url', 'https://api.openai.com/v1'), '/');
        $validationModel = (string) config('services.openai.face_validation_model', 'gpt-5.6-luna');
        $imageModel = (string) config('services.openai.image_model', 'gpt-image-2');

        if ($key === '') {
            $this->error('OpenAI diagnostic: API key is not configured.');

            return self::FAILURE;
        }

        $this->line('OpenAI diagnostic key fingerprint: '.substr(hash('sha256', $key), 0, 12));

        try {
            $validationModelResponse = $this->request($key)
                ->get($baseUrl.'/models/'.rawurlencode($validationModel));

            $this->report('model:'.$validationModel, $validationModelResponse);

            $imageModelResponse = $this->request($key)
                ->get($baseUrl.'/models/'.rawurlencode($imageModel));

            $this->report('model:'.$imageModel, $imageModelResponse);

            $responsesResponse = $this->request($key)
                ->post($baseUrl.'/responses', [
                    'model' => $validationModel,
                    'input' => 'Reply with exactly OK.',
                    'max_output_tokens' => 8,
                ]);

            $this->report('responses:'.$validationModel, $responsesResponse);

            return $validationModelResponse->successful()
                && $imageModelResponse->successful()
                && $responsesResponse->successful()
                    ? self::SUCCESS
                    : self::FAILURE;
        } catch (ConnectionException $exception) {
            $this->error('OpenAI diagnostic connection failure: '.$exception->getMessage());

            return self::FAILURE;
        } catch (Throwable $exception) {
            $this->error('OpenAI diagnostic unexpected failure: '.$exception::class.': '.$exception->getMessage());

            return self::FAILURE;
        }
    }

    private function request(string $key)
    {
        return Http::withToken($key)
            ->acceptJson()
            ->connectTimeout(10)
            ->timeout(30);
    }

    private function report(string $operation, Response $response): void
    {
        $providerCode = data_get($response->json(), 'error.code');
        $providerType = data_get($response->json(), 'error.type');
        $providerMessage = data_get($response->json(), 'error.message');
        $requestId = $response->header('x-request-id');

        $this->line(sprintf(
            'OpenAI diagnostic [%s] status=%d request_id=%s code=%s type=%s message=%s',
            $operation,
            $response->status(),
            $requestId ?: '-',
            is_scalar($providerCode) ? (string) $providerCode : '-',
            is_scalar($providerType) ? (string) $providerType : '-',
            is_scalar($providerMessage)
                ? mb_substr(preg_replace('/\s+/', ' ', (string) $providerMessage) ?: '', 0, 300)
                : '-',
        ));
    }
}
