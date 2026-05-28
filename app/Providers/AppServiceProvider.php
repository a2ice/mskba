<?php

namespace App\Providers;

use App\Presentation\Theming\ThemeResolver;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Bind the ThemeResolver as a singleton in the service container
        $this->app->singleton(ThemeResolver::class, function () {
            return new ThemeResolver(config('themes'));
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Register the theme's view namespace
        View::addNamespace('theme', app(ThemeResolver::class)->viewsPath());
    }
}
