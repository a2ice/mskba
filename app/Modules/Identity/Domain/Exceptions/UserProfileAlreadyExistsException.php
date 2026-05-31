<?php

namespace App\Modules\Identity\Domain\Exceptions;

use Exception;

final class UserProfileAlreadyExistsException extends Exception
{
    public function __construct()
    {
        parent::__construct('Профиль пользователя уже существует', 409);
    }
}
