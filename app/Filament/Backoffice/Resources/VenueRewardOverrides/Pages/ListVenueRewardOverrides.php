<?php

namespace App\Filament\Backoffice\Resources\VenueRewardOverrides\Pages;

use App\Filament\Backoffice\Resources\VenueRewardOverrides\VenueRewardOverrideResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListVenueRewardOverrides extends ListRecords
{
    protected static string $resource = VenueRewardOverrideResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
