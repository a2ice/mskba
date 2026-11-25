<?php

namespace App\Filament\Backoffice\Resources\VenueCatalogLandings\Pages;

use App\Filament\Backoffice\Resources\VenueCatalogLandings\VenueCatalogLandingResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListVenueCatalogLandings extends ListRecords
{
    protected static string $resource = VenueCatalogLandingResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
