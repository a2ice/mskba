<?php

namespace App\Modules\Media\Infrastructure\Providers;

use App\Modules\Venue\Domain\Models\Venue;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\ServiceProvider;

class MediaServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Relation::enforceMorphMap([
            'venue' => Venue::class,
        ]);
    }
}
