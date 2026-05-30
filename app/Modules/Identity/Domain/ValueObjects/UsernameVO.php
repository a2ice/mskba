<?php

namespace App\Modules\Identity\Domain\ValueObjects;

use App\Modules\Identity\Domain\Exceptions\InvalidIdentityValueException;

final readonly class UsernameVO
{
    public const MIN_LENGTH = 3;

    public const MAX_LENGTH = 32;

    private function __construct(
        public string $value,
    ) {}

    public static function fromString(string $value): self
    {
        $value = trim($value);

        if (mb_strlen($value) < self::MIN_LENGTH) {
            throw new InvalidIdentityValueException('Логин должен быть не менее '.self::MIN_LENGTH.' символов.');
        }

        if (mb_strlen($value) > self::MAX_LENGTH) {
            throw new InvalidIdentityValueException('Логин не должен превышать '.self::MAX_LENGTH.' символов.');
        }

        if (! preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*[A-Za-z0-9]$/', $value)) {
            throw new InvalidIdentityValueException('Логин может содержать латинские буквы, цифры, точку, дефис и подчёркивание, но должен начинаться и заканчиваться буквой или цифрой.');
        }

        return new self($value);
    }
}
