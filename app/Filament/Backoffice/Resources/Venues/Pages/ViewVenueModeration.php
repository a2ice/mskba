<?php

namespace App\Filament\Backoffice\Resources\Venues\Pages;

use App\Filament\Backoffice\Resources\Venues\VenueModerationResource;
use Filament\Resources\Pages\ViewRecord;

final class ViewVenueModeration extends ViewRecord
{
    protected static string $resource = VenueModerationResource::class;

    protected function getHeaderActions(): array
    {
        return VenueModerationResource::decisionActions();
    }
}
