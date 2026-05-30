<?php

namespace App\Modules\Venue\Domain\Exceptions;

use Exception;

final class VenueNotFoundException extends Exception
{
    public function __construct()
    {
        parent::__construct('Площадка не найдена', 404);
    }
}
