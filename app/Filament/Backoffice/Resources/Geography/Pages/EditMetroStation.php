<?php

namespace App\Filament\Backoffice\Resources\Geography\Pages;

use App\Filament\Backoffice\Resources\Geography\MetroStationResource;
use Filament\Resources\Pages\EditRecord;
use Filament\Actions\DeleteAction;

final class EditMetroStation extends EditRecord
{
    protected static string $resource = MetroStationResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
