<?php

namespace App\Filament\Backoffice\Resources\Geography\Pages;

use App\Filament\Backoffice\Resources\Geography\DistrictResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Actions\CreateAction;

final class ListDistrict extends ListRecords
{
    protected static string $resource = DistrictResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
