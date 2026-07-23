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
        return filled(config('telegram.bot_token')) && filled(config('telegram.main_chat_id'));
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
            throw new TelegramBotApiException(
                $this->safeError($exception, $token),
                previous: $exception,
            );
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
        $message = $exception instanceof RequestException && $exception->response !== null
            ? 'Telegram API returned HTTP '.$exception->response->status().'.'
            : 'Telegram API connection failed.';

        return str_replace($token, '***', $message);
    }
}
