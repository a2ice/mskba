<?php

namespace App\Modules\Event\Infrastructure\Jobs;

use App\Modules\Event\Application\Services\PlayerObjectiveAssessmentCalculator;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class RecalculatePlayerObjectiveAssessmentsJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $uniqueFor = 300;

    /** @var list<int> */
    public array $backoff = [10, 30, 90];

    public function __construct(public readonly int $gameId) {}

    public function uniqueId(): string
    {
        return (string) $this->gameId;
    }

    public function handle(PlayerObjectiveAssessmentCalculator $calculator): void
    {
        $calculator->recalculateForGame($this->gameId);
    }
}
