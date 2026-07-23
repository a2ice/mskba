<?php

namespace App\Modules\Portal\Infrastructure\Observers;

use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Portal\Application\Services\OnlineUserPresence;
use App\Modules\Portal\Application\Services\SiteSummaryService;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

final readonly class UserSiteSummaryObserver implements ShouldHandleEventsAfterCommit
{
    public function __construct(
        private SiteSummaryService $summary,
        private OnlineUserPresence $presence,
    ) {}

    public function saved(User $user): void
    {
        $this->summary->forgetTotalUsers();

        if ($user->status === UserStatusEnum::BLOCKED) {
            $this->presence->forget((int) $user->id);
        }
    }

    public function deleted(User $user): void
    {
        $this->summary->forgetTotalUsers();
        $this->presence->forget((int) $user->id);
    }

    public function restored(User $user): void
    {
        $this->summary->forgetTotalUsers();
    }
}
