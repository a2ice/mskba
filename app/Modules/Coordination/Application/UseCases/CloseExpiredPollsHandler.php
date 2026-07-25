<?php

namespace App\Modules\Coordination\Application\UseCases;

use App\Modules\Coordination\Domain\Enums\PollStatusEnum;
use App\Modules\Coordination\Domain\Models\Poll;

final class CloseExpiredPollsHandler
{
    public function __construct(private readonly ClosePollHandler $closePoll) {}

    public function handle(int $batchSize = 100): int
    {
        $pollIds = Poll::query()
            ->where('status', PollStatusEnum::OPEN)
            ->where('closes_at', '<=', now())
            ->orderBy('id')
            ->limit($batchSize)
            ->pluck('id');

        foreach ($pollIds as $pollId) {
            $this->closePoll->handle((int) $pollId);
        }

        return $pollIds->count();
    }
}
