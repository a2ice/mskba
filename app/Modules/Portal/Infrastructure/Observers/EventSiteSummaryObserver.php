<?php

namespace App\Modules\Portal\Infrastructure\Observers;

use App\Modules\Event\Domain\Models\Event;
use App\Modules\Portal\Application\Services\SiteSummaryService;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

final readonly class EventSiteSummaryObserver implements ShouldHandleEventsAfterCommit
{
    public function __construct(private SiteSummaryService $summary) {}

    public function saved(Event $event): void
    {
        $this->summary->forgetTodayEvents();
    }

    public function deleted(Event $event): void
    {
        $this->summary->forgetTodayEvents();
    }

    public function restored(Event $event): void
    {
        $this->summary->forgetTodayEvents();
    }
}
