<?php

namespace App\Modules\Identity\Domain\Exceptions;

use Exception;

final class UserCannotBeChangedException extends Exception
{
    public function __construct()
    {
        parent::__construct('Пользователь не может быть изменен', 403);
    }
}
