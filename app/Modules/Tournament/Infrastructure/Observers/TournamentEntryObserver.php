<?php

namespace App\Modules\Tournament\Infrastructure\Observers;

use App\Modules\Tournament\Application\Services\ContinuousTournamentScheduleExpander;
use App\Modules\Tournament\Domain\Enums\TournamentEntrySourceEnum;
use App\Modules\Tournament\Domain\Models\TournamentEntry;

final class TournamentEntryObserver
{
    public function created(TournamentEntry $entry): void
    {
        if ($entry->source !== TournamentEntrySourceEnum::TEAM) {
            return;
        }

        app(ContinuousTournamentScheduleExpander::class)->syncForEntry($entry);
    }
}
