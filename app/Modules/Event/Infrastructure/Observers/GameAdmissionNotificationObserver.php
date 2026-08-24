<?php

namespace App\Modules\Event\Infrastructure\Observers;

use App\Modules\Event\Application\Services\GameRecruitmentNotificationResolver;
use App\Modules\Event\Domain\Models\GameAdmission;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

final readonly class GameAdmissionNotificationObserver implements ShouldHandleEventsAfterCommit
{
    public function __construct(private GameRecruitmentNotificationResolver $notifications) {}

    public function updated(GameAdmission $admission): void
    {
        if (! $admission->wasChanged('status')) {
            return;
        }

        $this->notifications->resolve($admission);
    }
}
