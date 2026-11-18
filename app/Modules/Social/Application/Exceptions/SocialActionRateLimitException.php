<?php

namespace App\Modules\Social\Application\Exceptions;

use RuntimeException;

final class SocialActionRateLimitException extends RuntimeException
{
    public function __construct(public readonly int $retryAfter)
    {
        parent::__construct('Слишком много действий. Повтори попытку немного позже.');
    }
}
