<?php

namespace App\Modules\Identity\Domain\Exceptions;

use Exception;

final class UserCannotBeChangedException extends Exception
{
    public function __construct(string $message = 'Пользователь не может быть изменен', int $code = 403)
    {
        parent::__construct($message, $code);
    }
}
