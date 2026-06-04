<?php

namespace App\Modules\Contact\Infrastructure\Providers;

use App\Modules\Contact\Application\Services\ContactVerificationStrategyResolver;
use App\Modules\Contact\Application\Strategies\EmailContactVerificationStrategy;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Domain\Models\Venue;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\ServiceProvider;

class ContactServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ContactVerificationStrategyResolver::class, function (): ContactVerificationStrategyResolver {
            return new ContactVerificationStrategyResolver([
                $this->app->make(EmailContactVerificationStrategy::class),
            ]);
        });
    }

    public function boot(): void
    {
        Relation::enforceMorphMap([
            'user' => User::class,
            'venue' => Venue::class,
        ]);
    }
}
