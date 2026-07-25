<?php

namespace App\Modules\Coordination\Domain\Events;

final readonly class PollActivated
{
    public function __construct(
        public int $previousPollId,
        public int $pollId,
    ) {}
}
