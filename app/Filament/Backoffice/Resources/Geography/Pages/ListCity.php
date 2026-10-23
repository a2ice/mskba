<?php

namespace App\Filament\Backoffice\Resources\Geography\Pages;

use App\Filament\Backoffice\Resources\Geography\CityResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Actions\CreateAction;

final class ListCity extends ListRecords
{
    protected static string $resource = CityResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
