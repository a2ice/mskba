<?php

namespace App\Modules\Contact\Application\Exceptions;

use RuntimeException;

class ContactDeletionException extends RuntimeException
{
    public static function primaryContact(): self
    {
        return new self('Нельзя удалить основной контакт.');
    }
}
