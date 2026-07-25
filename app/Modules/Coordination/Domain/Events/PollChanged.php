<?php

namespace App\Modules\Coordination\Domain\Events;

final readonly class PollChanged
{
    public function __construct(public int $pollId) {}
}
