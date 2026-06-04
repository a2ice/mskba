<?php

namespace App\Modules\Contact\Application\Exceptions;

use RuntimeException;

class ContactVerificationCooldownException extends RuntimeException
{
    public function __construct(
        public readonly int $secondsLeft,
    ) {
        parent::__construct('Код уже отправлен. Новый код можно запросить через :seconds сек.');
    }
}
