<?php

namespace App\Modules\Venue\Infrastructure\Observers;

use App\Modules\Venue\Application\Services\VenueSearchCache;
use Illuminate\Database\Eloquent\Model;

final readonly class VenueSearchCacheObserver
{
    public function __construct(private VenueSearchCache $cache) {}

    public function saved(Model $model): void
    {
        $this->cache->invalidate();
    }

    public function deleted(Model $model): void
    {
        $this->cache->invalidate();
    }

    public function restored(Model $model): void
    {
        $this->cache->invalidate();
    }
}
