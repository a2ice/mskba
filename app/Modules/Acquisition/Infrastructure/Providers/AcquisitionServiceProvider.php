<?php

namespace App\Modules\Acquisition\Infrastructure\Providers;

use App\Modules\Acquisition\Application\Services\AcquisitionTracker;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

final class AcquisitionServiceProvider extends ServiceProvider
{
    public function boot(AcquisitionTracker $tracker): void
    {
        Event::listen(Login::class, function (Login $event) use ($tracker): void {
            if (! $event->user instanceof User || ! app()->bound('request')) {
                return;
            }

            $tracker->attachUser(request(), $event->user);
        });
    }
}
