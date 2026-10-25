<?php

namespace App\Modules\Favorites\Infrastructure\Providers;

use App\Modules\Event\Domain\Models\Event;
use App\Modules\Favorites\Infrastructure\Observers\FavoriteEventObserver;
use App\Modules\Favorites\Infrastructure\Observers\FavoriteTournamentObserver;
use App\Modules\Tournament\Domain\Models\Tournament;
use Illuminate\Support\ServiceProvider;

final class FavoritesServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Event::observe(FavoriteEventObserver::class);
        Tournament::observe(FavoriteTournamentObserver::class);
    }
}
