<?php

namespace App\Modules\Venue\Domain\Exceptions;

use Exception;

final class VenueAccessDeniedException extends Exception
{
    public function __construct()
    {
        parent::__construct('Доступ запрещен', 403);
    }
}
