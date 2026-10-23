<?php

namespace App\Filament\Backoffice\Resources\Geography\Pages;

use App\Filament\Backoffice\Resources\Geography\DistrictResource;
use Filament\Resources\Pages\EditRecord;
use Filament\Actions\DeleteAction;

final class EditDistrict extends EditRecord
{
    protected static string $resource = DistrictResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
