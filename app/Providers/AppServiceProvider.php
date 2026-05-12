<?php

namespace App\Providers;

use App\Modules\Contact\Domain\Services\ContactValueChecker;
use App\Modules\Identity\Application\Contracts\ContactValueCheckerContract;
use Illuminate\Support\ServiceProvider;

use App\Presentation\Theming\ThemeResolver;
use Illuminate\Support\Facades\View;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(ContactValueCheckerContract::class, ContactValueChecker::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        View::addNamespace('theme', app(ThemeResolver::class)->viewsPath());
    }
}
