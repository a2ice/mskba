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
            $request = $this->request($timeoutSeconds, asJson: false)->asMultipart();

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

    public function downloadFile(string $filePath, ?int $timeoutSeconds = null): string
    {
        $token = (string) config('telegram.bot_token');
        if ($token === '' || $filePath === '' || str_contains($filePath, '..')) {
            throw new TelegramBotApiException('Telegram file path is invalid.');
        }

        try {
            return $this->request($timeoutSeconds, asJson: false)
                ->get($this->fileUrl($token, $filePath))
                ->throw()
                ->body();
        } catch (ConnectionException|RequestException $exception) {
            throw new TelegramBotApiException($this->safeError($exception, $token));
        }
    }

    private function request(?int $timeoutSeconds = null, bool $asJson = true): PendingRequest
    {
        $options = [];
        $curlOptions = [];
        $proxy = trim((string) config('telegram.http_proxy'));
        $apiIp = trim((string) config('telegram.api_ip'));
        $forceIpv4 = (bool) config('telegram.force_ipv4', false);
        $baseHost = parse_url((string) config('telegram.api_base_url'), PHP_URL_HOST);

        if ($proxy !== '') {
            $options['proxy'] = $proxy;
        }

        if ($forceIpv4) {
            $curlOptions[CURLOPT_IPRESOLVE] = CURL_IPRESOLVE_V4;
        }

        if ($apiIp !== '' && is_string($baseHost) && $baseHost !== '') {
            $curlOptions[CURLOPT_RESOLVE] = ["{$baseHost}:443:{$apiIp}"];
            $curlOptions[CURLOPT_IPRESOLVE] = CURL_IPRESOLVE_V4;
        }

        if ($curlOptions !== []) {
            $options['curl'] = $curlOptions;
        }

        $request = Http::withOptions($options)
            ->timeout($timeoutSeconds ?? 15)
            ->connectTimeout(8);

        return $asJson ? $request->asJson() : $request;
    }

    private function apiUrl(string $token, string $method): string
    {
        $baseUrl = rtrim((string) config('telegram.api_base_url', 'https://api.telegram.org'), '/');

        return "{$baseUrl}/bot{$token}/{$method}";
    }

    private function fileUrl(string $token, string $filePath): string
    {
        $baseUrl = rtrim((string) config('telegram.api_base_url', 'https://api.telegram.org'), '/');

        return "{$baseUrl}/file/bot{$token}/".ltrim($filePath, '/');
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
