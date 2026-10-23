<?php

namespace App\Filament\Backoffice\Resources\Venues\Pages;

use App\Filament\Backoffice\Resources\Venues\AmenityResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListAmenities extends ListRecords
{
    protected static string $resource = AmenityResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
