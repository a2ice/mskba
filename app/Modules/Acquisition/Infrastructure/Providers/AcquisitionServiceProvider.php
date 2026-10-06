<?php

namespace App\Modules\Acquisition\Infrastructure\Providers;

use App\Modules\Acquisition\Application\Services\AcquisitionTracker;
use App\Modules\Acquisition\Application\Services\ReferralAttributionService;
use App\Modules\Identity\Domain\Events\UserFirstLogin;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

final class AcquisitionServiceProvider extends ServiceProvider
{
    public function boot(AcquisitionTracker $tracker, ReferralAttributionService $referrals): void
    {
        Event::listen(Login::class, function (Login $event) use ($tracker): void {
            if (! $event->user instanceof User || ! app()->bound('request')) {
                return;
            }

            $tracker->attachUser(request(), $event->user);
        });

        Event::listen(UserFirstLogin::class, function (UserFirstLogin $event) use ($referrals): void {
            if (! app()->bound('request')) {
                return;
            }

            $user = User::query()->find($event->userId);

            if ($user !== null) {
                $referrals->attachPending(request(), $user);
            }
        });
    }
}
