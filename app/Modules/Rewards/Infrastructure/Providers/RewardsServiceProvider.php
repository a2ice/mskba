<?php

namespace App\Modules\Rewards\Infrastructure\Providers;

use App\Modules\Identity\Domain\Events\UserAccountConfirmed;
use App\Modules\Moderation\Domain\Events\ModerationRequestApproved;
use App\Modules\Rewards\Infrastructure\Listeners\ProcessUserAccountConfirmedRewards;
use App\Modules\Rewards\Infrastructure\Listeners\ProcessVenueFirstApprovalReward;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

final class RewardsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Event::listen(UserAccountConfirmed::class, ProcessUserAccountConfirmedRewards::class);
        Event::listen(ModerationRequestApproved::class, ProcessVenueFirstApprovalReward::class);
    }
}
