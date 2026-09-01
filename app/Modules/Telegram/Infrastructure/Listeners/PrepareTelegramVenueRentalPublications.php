<?php

namespace App\Modules\Telegram\Infrastructure\Listeners;

use App\Modules\Coordination\Domain\Events\VenueRentalCoordinationCreated;
use App\Modules\Coordination\Domain\Models\VenueRentalCoordination;
use App\Modules\Telegram\Domain\Models\TelegramChat;
use App\Modules\Telegram\Domain\Models\TelegramVenueRentalPublication;
use App\Modules\Telegram\Infrastructure\Jobs\SyncTelegramVenueRentalPublicationJob;
use App\Support\Features\FeatureFlags;
use App\Support\Features\VenueRentalFeature;

final readonly class PrepareTelegramVenueRentalPublications
{
    public function __construct(private FeatureFlags $features) {}

    public function handle(VenueRentalCoordinationCreated $event): void
    {
        if (! $this->features->enabled(VenueRentalFeature::COORDINATION)) {
            return;
        }
        $coordination = VenueRentalCoordination::query()->find($event->coordinationId);
        if ($coordination === null || $coordination->visibility !== 'public') {
            return;
        }

        TelegramChat::query()
            ->where('is_active', true)
            ->where('publishes_coordination', true)
            ->pluck('id')
            ->each(function ($chatId) use ($coordination): void {
                $publication = TelegramVenueRentalPublication::query()->firstOrCreate(
                    ['coordination_id' => $coordination->id, 'chat_id' => (int) $chatId],
                    ['status' => 'pending'],
                );
                SyncTelegramVenueRentalPublicationJob::dispatch($publication->id)->afterCommit();
            });
    }
}
