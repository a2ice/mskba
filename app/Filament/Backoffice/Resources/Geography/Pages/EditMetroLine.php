<?php

namespace App\Filament\Backoffice\Resources\Geography\Pages;

use App\Filament\Backoffice\Resources\Geography\MetroLineResource;
use Filament\Resources\Pages\EditRecord;
use Filament\Actions\DeleteAction;

final class EditMetroLine extends EditRecord
{
    protected static string $resource = MetroLineResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
