<?php

namespace App\Modules\Venue\Domain\Exceptions;

use RuntimeException;

final class VenuePendingModerationException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Площадка находится на модерации. Дождитесь решения перед редактированием.', 409);
    }
}
