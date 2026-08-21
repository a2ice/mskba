<?php

namespace App\Modules\Vk\Infrastructure\Services;

use App\Modules\Vk\Application\DTO\VkUserIdentityDTO;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

final class VkIdClient
{
    /** @return array{access_token: string, user_id: string} */
    public function exchangeCode(string $code, string $deviceId, string $codeVerifier, string $state): array
    {
        $url = rtrim((string) config('vk.token_url'), '?').'?'.http_build_query([
            'grant_type' => 'authorization_code',
            'redirect_uri' => $this->redirectUri(),
            'client_id' => $this->appId(),
            'code_verifier' => $codeVerifier,
            'state' => $state,
            'device_id' => $deviceId,
        ], '', '&', PHP_QUERY_RFC3986);
        $response = $this->http()->asForm()->post($url, [
            'code' => $code,
        ]);

        if (! $response->successful()) {
            throw new InvalidArgumentException('VK ID не подтвердил вход. Попробуйте ещё раз.');
        }

        $data = $response->json();
        $accessToken = is_array($data) ? ($data['access_token'] ?? null) : null;
        $userId = is_array($data) ? ($data['user_id'] ?? null) : null;
        $returnedState = is_array($data) ? ($data['state'] ?? null) : null;

        if (! is_string($accessToken) || $accessToken === '' || (! is_string($userId) && ! is_int($userId))) {
            throw new InvalidArgumentException('VK ID вернул неполные данные авторизации.');
        }

        if (! is_string($returnedState) || ! hash_equals($state, $returnedState)) {
            throw new InvalidArgumentException('VK ID вернул некорректное состояние авторизации.');
        }

        return ['access_token' => $accessToken, 'user_id' => (string) $userId];
    }

    public function userInfo(string $accessToken): VkUserIdentityDTO
    {
        $url = rtrim((string) config('vk.user_info_url'), '?').'?'.http_build_query([
            'client_id' => $this->appId(),
        ], '', '&', PHP_QUERY_RFC3986);
        $response = $this->http()->asForm()->post($url, [
            'access_token' => $accessToken,
        ]);

        $data = $response->successful() ? $response->json() : null;
        $user = is_array($data) && is_array($data['user'] ?? null) ? $data['user'] : null;
        $userId = $user['user_id'] ?? null;

        if ($user === null || (! is_string($userId) && ! is_int($userId))) {
            throw new InvalidArgumentException('Не удалось получить профиль VK ID.');
        }

        return new VkUserIdentityDTO(
            id: (string) $userId,
            firstName: $this->nullableString($user['first_name'] ?? null),
            lastName: $this->nullableString($user['last_name'] ?? null),
            avatarUrl: $this->nullableString($user['avatar'] ?? null),
            rawData: array_filter([
                'user_id' => (string) $userId,
                'first_name' => $this->nullableString($user['first_name'] ?? null),
                'last_name' => $this->nullableString($user['last_name'] ?? null),
                'avatar' => $this->nullableString($user['avatar'] ?? null),
                'sex' => $user['sex'] ?? $user['gender'] ?? null,
                'birthday' => $user['birthday'] ?? $user['birth_date'] ?? $user['bdate'] ?? null,
            ], static fn (mixed $value): bool => $value !== null),
            gender: $this->normalizeGender($user['sex'] ?? $user['gender'] ?? null),
            birthDate: $this->normalizeBirthDate(
                $user['birthday'] ?? $user['birth_date'] ?? $user['bdate'] ?? null,
            ),
        );
    }

    private function http(): PendingRequest
    {
        return Http::acceptJson()->timeout(10)->retry(2, 150, throw: false);
    }

    private function appId(): string
    {
        $appId = trim((string) config('vk.app_id'));

        if ($appId === '') {
            throw new InvalidArgumentException('Вход через VK ID сейчас недоступен.');
        }

        return $appId;
    }

    private function redirectUri(): string
    {
        return trim((string) config('vk.redirect_uri')) ?: route('auth.vk.callback');
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function normalizeGender(mixed $value): ?string
    {
        if (is_int($value) || (is_string($value) && ctype_digit($value))) {
            return match ((int) $value) {
                1 => 'female',
                2 => 'male',
                default => null,
            };
        }

        return match (is_string($value) ? strtolower(trim($value)) : null) {
            'female', 'f' => 'female',
            'male', 'm' => 'male',
            default => null,
        };
    }

    private function normalizeBirthDate(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);
        foreach (['!Y-m-d', '!d.m.Y', '!Y.m.d'] as $format) {
            $date = \DateTimeImmutable::createFromFormat($format, $value);
            $errors = \DateTimeImmutable::getLastErrors();

            if (
                $date !== false
                && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
                && $date <= new \DateTimeImmutable('today')
            ) {
                return $date->format('Y-m-d');
            }
        }

        return null;
    }
}
