<?php

namespace App\Modules\Telegram\Infrastructure\Services;

use App\Modules\Telegram\Infrastructure\Exceptions\TelegramBotApiException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Throwable;

final class TelegramBotApiClient
{
    public function isConfigured(): bool
    {
        return $this->isBotConfigured() && filled(config('telegram.main_chat_id'));
    }

    public function isBotConfigured(): bool
    {
        return filled(config('telegram.bot_token'));
    }

    /** @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function call(string $method, array $payload = [], ?int $timeoutSeconds = null): array
    {
        $token = (string) config('telegram.bot_token');

        if ($token === '') {
            throw new TelegramBotApiException('Telegram bot token is not configured.');
        }

        try {
            $response = $this->request($timeoutSeconds)
                ->post($this->apiUrl($token, $method), $payload)
                ->throw()
                ->json();

            if (! is_array($response) || data_get($response, 'ok') !== true) {
                throw new TelegramBotApiException('Telegram API returned an invalid response.');
            }

            return $response;
        } catch (ConnectionException|RequestException $exception) {
            throw new TelegramBotApiException($this->safeError($exception, $token));
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, array{contents: string, filename: string, mime: string}>  $files
     * @return array<string, mixed>
     */
    public function callMultipart(
        string $method,
        array $payload,
        array $files,
        ?int $timeoutSeconds = null,
    ): array {
        $token = (string) config('telegram.bot_token');

        if ($token === '') {
            throw new TelegramBotApiException('Telegram bot token is not configured.');
        }

        try {
            $request = $this->request($timeoutSeconds)->asMultipart();

            foreach ($files as $field => $file) {
                $request = $request->attach(
                    $field,
                    $file['contents'],
                    $file['filename'],
                    ['Content-Type' => $file['mime']],
                );
            }

            $response = $request
                ->post($this->apiUrl($token, $method), $payload)
                ->throw()
                ->json();

            if (! is_array($response) || data_get($response, 'ok') !== true) {
                throw new TelegramBotApiException('Telegram API returned an invalid response.');
            }

            return $response;
        } catch (ConnectionException|RequestException $exception) {
            throw new TelegramBotApiException($this->safeError($exception, $token));
        }
    }

    private function request(?int $timeoutSeconds = null): PendingRequest
    {
        $options = [];
        $proxy = trim((string) config('telegram.http_proxy'));
        $apiIp = trim((string) config('telegram.api_ip'));
        $baseHost = parse_url((string) config('telegram.api_base_url'), PHP_URL_HOST);

        if ($proxy !== '') {
            $options['proxy'] = $proxy;
        }

        if ($apiIp !== '' && is_string($baseHost) && $baseHost !== '') {
            $options['curl'] = [
                CURLOPT_RESOLVE => ["{$baseHost}:443:{$apiIp}"],
                CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
            ];
        }

        return Http::asJson()
            ->withOptions($options)
            ->timeout($timeoutSeconds ?? 15)
            ->connectTimeout(8);
    }

    private function apiUrl(string $token, string $method): string
    {
        $baseUrl = rtrim((string) config('telegram.api_base_url', 'https://api.telegram.org'), '/');

        return "{$baseUrl}/bot{$token}/{$method}";
    }

    private function safeError(Throwable $exception, string $token): string
    {
        if ($exception instanceof RequestException && $exception->response !== null) {
            $description = $exception->response->json('description');
            $message = 'Telegram API returned HTTP '.$exception->response->status().'.'
                .(is_string($description) && $description !== '' ? ' '.$description : '');
        } else {
            $message = 'Telegram API connection failed.';
        }

        return str_replace($token, '***', $message);
    }
}
