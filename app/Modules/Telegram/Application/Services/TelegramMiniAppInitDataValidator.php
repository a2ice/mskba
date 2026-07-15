<?php

namespace App\Modules\Telegram\Application\Services;

use App\Modules\Telegram\Application\DTO\TelegramMiniAppUserDTO;
use InvalidArgumentException;

final class TelegramMiniAppInitDataValidator
{
    public function validate(string $initData): TelegramMiniAppUserDTO
    {
        $botToken = (string) config('telegram.bot_token');

        if ($botToken === '') {
            throw new InvalidArgumentException('Telegram bot token is not configured.');
        }

        parse_str($initData, $data);

        if (! is_array($data) || ! isset($data['hash']) || ! is_string($data['hash'])) {
            throw new InvalidArgumentException('Telegram init data hash is missing.');
        }

        $hash = $data['hash'];
        unset($data['hash']);

        ksort($data);
        $dataCheckString = collect($data)
            ->map(fn (mixed $value, string $key): string => $key.'='.$this->stringValue($value))
            ->implode("\n");

        $secretKey = hash_hmac('sha256', $botToken, 'WebAppData', true);
        $calculatedHash = hash_hmac('sha256', $dataCheckString, $secretKey);

        if (! hash_equals($calculatedHash, $hash)) {
            throw new InvalidArgumentException('Telegram init data signature is invalid.');
        }

        $authDate = filter_var($data['auth_date'] ?? null, FILTER_VALIDATE_INT);
        if (! is_int($authDate)) {
            throw new InvalidArgumentException('Telegram auth date is missing.');
        }

        $maxAge = (int) config('telegram.init_data_max_age', 86400);
        if ($maxAge > 0 && $authDate < now()->subSeconds($maxAge)->timestamp) {
            throw new InvalidArgumentException('Telegram init data is expired.');
        }

        $user = json_decode($this->stringValue($data['user'] ?? ''), true);
        if (! is_array($user)) {
            throw new InvalidArgumentException('Telegram user payload is missing.');
        }

        $telegramUserId = filter_var($user['id'] ?? null, FILTER_VALIDATE_INT);
        if (! is_int($telegramUserId)) {
            throw new InvalidArgumentException('Telegram user id is missing.');
        }

        return new TelegramMiniAppUserDTO(
            id: $telegramUserId,
            username: $this->nullableString($user['username'] ?? null),
            firstName: $this->nullableString($user['first_name'] ?? null),
            lastName: $this->nullableString($user['last_name'] ?? null),
            languageCode: $this->nullableString($user['language_code'] ?? null),
            photoUrl: $this->nullableString($user['photo_url'] ?? null),
            authDate: $authDate,
            startParam: $this->nullableString($data['start_param'] ?? null),
            chatType: $this->nullableString($data['chat_type'] ?? null),
            chatInstance: $this->nullableString($data['chat_instance'] ?? null),
            rawUser: $user,
        );
    }

    private function stringValue(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_scalar($value) || $value === null) {
            return (string) $value;
        }

        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
