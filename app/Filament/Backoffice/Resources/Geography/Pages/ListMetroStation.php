<?php

namespace App\Filament\Backoffice\Resources\Geography\Pages;

use App\Filament\Backoffice\Resources\Geography\MetroStationResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Actions\CreateAction;

final class ListMetroStation extends ListRecords
{
    protected static string $resource = MetroStationResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
