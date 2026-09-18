<?php

namespace App\Modules\Acquisition\Infrastructure\Providers;

use App\Modules\Acquisition\Presentation\Http\Middleware\LinkAcquisitionVisitToAuthenticatedUser;
use Illuminate\Support\ServiceProvider;

final class AcquisitionServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->app['router']->pushMiddlewareToGroup('web', LinkAcquisitionVisitToAuthenticatedUser::class);
    }
}
