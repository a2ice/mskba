<?php

namespace App\Modules\Telegram\Application\Services;

use App\Modules\Telegram\Application\DTO\TelegramLoginWidgetUserDTO;
use InvalidArgumentException;

final class TelegramLoginWidgetDataValidator
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function validate(array $payload): TelegramLoginWidgetUserDTO
    {
        $botToken = (string) config('telegram.bot_token');

        if ($botToken === '') {
            throw new InvalidArgumentException('Вход через Telegram временно не настроен.');
        }

        $hash = $payload['hash'] ?? null;
        if (! is_string($hash) || ! preg_match('/^[a-f0-9]{64}$/i', $hash)) {
            throw new InvalidArgumentException('Подпись Telegram отсутствует.');
        }

        unset($payload['hash']);

        foreach ($payload as $value) {
            if (! is_scalar($value) && $value !== null) {
                throw new InvalidArgumentException('Telegram передал некорректные данные.');
            }
        }

        ksort($payload);
        $dataCheckString = collect($payload)
            ->map(fn (mixed $value, string $key): string => $key.'='.$this->stringValue($value))
            ->implode("\n");
        $secretKey = hash('sha256', $botToken, true);
        $calculatedHash = hash_hmac('sha256', $dataCheckString, $secretKey);

        if (! hash_equals(strtolower($calculatedHash), strtolower($hash))) {
            throw new InvalidArgumentException('Подпись Telegram недействительна.');
        }

        $authDate = filter_var($payload['auth_date'] ?? null, FILTER_VALIDATE_INT);
        if (! is_int($authDate)) {
            throw new InvalidArgumentException('Telegram не передал время авторизации.');
        }

        $maxAge = (int) config('telegram.login_widget_max_age', 600);
        if (
            ($maxAge > 0 && $authDate < now()->subSeconds($maxAge)->timestamp)
            || $authDate > now()->addMinute()->timestamp
        ) {
            throw new InvalidArgumentException('Срок данных авторизации Telegram истёк.');
        }

        $telegramUserId = filter_var($payload['id'] ?? null, FILTER_VALIDATE_INT);
        if (! is_int($telegramUserId)) {
            throw new InvalidArgumentException('Telegram не передал идентификатор пользователя.');
        }

        return new TelegramLoginWidgetUserDTO(
            id: $telegramUserId,
            username: $this->nullableString($payload['username'] ?? null),
            firstName: $this->nullableString($payload['first_name'] ?? null),
            lastName: $this->nullableString($payload['last_name'] ?? null),
            photoUrl: $this->nullableString($payload['photo_url'] ?? null),
            authDate: $authDate,
            rawUser: $payload,
        );
    }

    private function stringValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return is_scalar($value) || $value === null ? (string) $value : '';
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
