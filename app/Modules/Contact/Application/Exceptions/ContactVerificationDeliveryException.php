<?php

namespace App\Modules\Contact\Application\Exceptions;

use RuntimeException;

final class ContactVerificationDeliveryException extends RuntimeException
{
    public static function unavailable(): self
    {
        return new self('Отправка писем на этом сервере пока не настроена. Попробуйте позже.');
    }

    public static function failed(): self
    {
        return new self('Не удалось отправить код на почту. Попробуйте позже.');
    }
}
