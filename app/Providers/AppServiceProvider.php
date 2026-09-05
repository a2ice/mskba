<?php

namespace App\Providers;

use App\Modules\Event\Application\Listeners\RecalculatePlayerObjectiveAssessments;
use App\Modules\Event\Domain\Enums\GameRecruitmentModeEnum;
use App\Modules\Event\Domain\Events\GameStatisticsConfirmed;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Event\Domain\Models\VenueBooking;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Location\Domain\Models\Address;
use App\Modules\Location\Domain\Models\Location;
use App\Modules\Location\Domain\Models\MetroStation;
use App\Modules\Portal\Application\Services\SiteSummaryService;
use App\Modules\Portal\Infrastructure\Observers\EventSiteSummaryObserver;
use App\Modules\Portal\Infrastructure\Observers\UserSiteSummaryObserver;
use App\Modules\Tournament\Domain\Models\TournamentEntry;
use App\Modules\Tournament\Infrastructure\Observers\TournamentEntryObserver;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\Venue\Domain\Models\VenueSchedule;
use App\Modules\Venue\Domain\Models\VenueScheduleException;
use App\Modules\Venue\Domain\Models\VenueScheduleExceptionInterval;
use App\Modules\Venue\Domain\Models\VenueScheduleInterval;
use App\Modules\Venue\Domain\Models\VenueTag;
use App\Modules\Venue\Infrastructure\Observers\VenueSearchCacheObserver;
use App\Presentation\Navigation\ConfigMenuResolver;
use App\Presentation\Navigation\MenuResolver;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\Support\Facades\Event as EventFacade;
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

        $this->app->singleton(MenuResolver::class, ConfigMenuResolver::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        EventFacade::listen(
            GameStatisticsConfirmed::class,
            RecalculatePlayerObjectiveAssessments::class,
        );

        // Register the theme's view namespace
        View::addNamespace('theme', app(ThemeResolver::class)->viewsPath());
        View::composer([
            'theme::pages.welcome',
            'theme::partials.mobile-primary-bar',
        ], function ($view): void {
            $view->with('siteSummary', app(SiteSummaryService::class)->get());
        });
        View::composer('theme::pages.events.game-show', function ($view): void {
            $game = $view->getData()['game'] ?? null;

            if ($game?->recruitment_mode === null) {
                $game->setAttribute('recruitment_mode', GameRecruitmentModeEnum::PREFORMED_TEAMS->value);
            }
        });

        Event::observe(EventSiteSummaryObserver::class);
        User::observe(UserSiteSummaryObserver::class);
        TournamentEntry::observe(TournamentEntryObserver::class);

        foreach ([
            Venue::class,
            VenueTag::class,
            VenueSchedule::class,
            VenueScheduleInterval::class,
            VenueScheduleException::class,
            VenueScheduleExceptionInterval::class,
            VenueBooking::class,
            Location::class,
            Address::class,
            MetroStation::class,
        ] as $model) {
            $model::observe(VenueSearchCacheObserver::class);
        }
    }
}
