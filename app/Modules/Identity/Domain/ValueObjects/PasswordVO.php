<?php

namespace App\Modules\Identity\Domain\ValueObjects;

use App\Modules\Identity\Domain\Exceptions\InvalidIdentityValueException;

final readonly class PasswordVO
{
    public const MIN_LENGTH = 6;

    public const MAX_LENGTH = 255;

    private function __construct(
        public string $value,
    ) {}

    public static function fromString(string $value): self
    {
        if ($value !== trim($value)) {
            throw new InvalidIdentityValueException('Пароль не должен начинаться или заканчиваться пробелом.');
        }

        if (mb_strlen($value) < self::MIN_LENGTH) {
            throw new InvalidIdentityValueException('Пароль должен быть не менее '.self::MIN_LENGTH.' символов.');
        }

        if (mb_strlen($value) > self::MAX_LENGTH) {
            throw new InvalidIdentityValueException('Пароль не должен превышать '.self::MAX_LENGTH.' символов.');
        }

        if (! preg_match('/[A-Z]/', $value)) {
            throw new InvalidIdentityValueException('Пароль должен содержать хотя бы одну заглавную букву.');
        }

        if (! preg_match('/[a-z]/', $value)) {
            throw new InvalidIdentityValueException('Пароль должен содержать хотя бы одну строчную букву.');
        }

        if (! preg_match('/[0-9]/', $value)) {
            throw new InvalidIdentityValueException('Пароль должен содержать хотя бы одну цифру.');
        }

        if (! preg_match('/[\W_]/', $value)) {
            throw new InvalidIdentityValueException('Пароль должен содержать хотя бы один специальный символ.');
        }

        return new self($value);
    }
}
