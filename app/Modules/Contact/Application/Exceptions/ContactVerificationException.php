<?php

namespace App\Modules\Contact\Application\Exceptions;

use RuntimeException;

class ContactVerificationException extends RuntimeException
{
    public static function noActiveCode(): self
    {
        return new self('Нет активного кода подтверждения.');
    }

    public static function expired(): self
    {
        return new self('Срок действия кода истек. Запросите новый код.');
    }

    public static function attemptsLimitReached(): self
    {
        return new self('Лимит попыток исчерпан. Запросите новый код.');
    }

    public static function invalidCode(int $attemptsLeft): self
    {
        return new self("Неверный код. Осталось попыток: {$attemptsLeft}.");
    }

    public static function invalidCodeAndAttemptsLimitReached(): self
    {
        return new self('Неверный код. Лимит попыток исчерпан. Запросите новый код.');
    }
}
