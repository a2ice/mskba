<?php

namespace App\Modules\Telegram\Infrastructure\Listeners;

use App\Modules\Coordination\Domain\Events\VenueRentalCoordinationClosed;
use App\Modules\Coordination\Domain\Events\VenueRentalCoordinationConverted;
use App\Modules\Coordination\Domain\Events\VenueRentalCoordinationJoined;
use App\Modules\Telegram\Domain\Models\TelegramVenueRentalPublication;
use App\Modules\Telegram\Infrastructure\Jobs\SyncTelegramVenueRentalPublicationJob;
use App\Support\Features\FeatureFlags;
use App\Support\Features\VenueRentalFeature;

final readonly class QueueTelegramVenueRentalPublicationSync
{
    public function __construct(private FeatureFlags $features) {}

    public function handle(VenueRentalCoordinationJoined|VenueRentalCoordinationClosed|VenueRentalCoordinationConverted $event): void
    {
        if (! $this->features->enabled(VenueRentalFeature::COORDINATION)) {
            return;
        }

        TelegramVenueRentalPublication::query()
            ->where('coordination_id', $event->coordinationId)
            ->pluck('id')
            ->each(fn ($publicationId) => SyncTelegramVenueRentalPublicationJob::dispatch((int) $publicationId)->afterCommit());
    }
}
