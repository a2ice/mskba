<?php

namespace App\Modules\Identity\Domain\ValueObjects;

use InvalidArgumentException;

final class PasswordVO
{
    private const TEMP_PASSWORD_LENGTH = 6;
    private const TEMP_PASSWORD_CHARS = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';

    private string $value;

    /**
     * @throws InvalidArgumentException
     */
    public function __construct(string $plain)
    {
        $plain = trim($plain);

        if (! self::validate($plain)) {
            throw new InvalidArgumentException('Пароль должен содержать только латинские буквы и цифры.');
        }

        $this->value = $plain;
    }

    public static function validate(string $plain): bool
    {
        return mb_strlen($plain) >= self::TEMP_PASSWORD_LENGTH
            && preg_match('/^[a-zA-Z0-9]+$/', $plain) === 1;
    }

    public function __toString(): string
    {
        return $this->value;
    }

    public static function generate(int $length = self::TEMP_PASSWORD_LENGTH): self
    {
        $password = '';
        $maxIndex = strlen(self::TEMP_PASSWORD_CHARS) - 1;

        for ($i = 0; $i < $length; $i++) {
            $password .= self::TEMP_PASSWORD_CHARS[random_int(0, $maxIndex)];
        }

        return new self($password);
    }
}
