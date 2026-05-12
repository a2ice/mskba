<?php

namespace App\Providers;

use App\Modules\Contact\Domain\Services\ContactValueChecker;
use App\Modules\Identity\Application\Contracts\ContactValueCheckerContract;
use App\Modules\Identity\Application\Contracts\UserReadRepositoryContract;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Identity\Infrastructure\Persistence\EloquentUserReadRepository;
use Illuminate\Database\Eloquent\Relations\Relation;
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
        $this->app->bind(UserReadRepositoryContract::class, EloquentUserReadRepository::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Relation::enforceMorphMap([
            'user' => User::class,
        ]);

        View::addNamespace('theme', app(ThemeResolver::class)->viewsPath());
    }
}
