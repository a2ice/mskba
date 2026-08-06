<?php

namespace App\Modules\Event\Application\Listeners;

use App\Modules\Event\Domain\Events\GameStatisticsConfirmed;
use App\Modules\Event\Infrastructure\Jobs\RecalculatePlayerObjectiveAssessmentsJob;

final class RecalculatePlayerObjectiveAssessments
{
    public function handle(GameStatisticsConfirmed $event): void
    {
        RecalculatePlayerObjectiveAssessmentsJob::dispatch($event->gameId)->afterCommit();
    }
}
