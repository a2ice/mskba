<?php

namespace App\Filament\Backoffice\Resources\Events\Pages;

use App\Filament\Backoffice\Resources\Events\EventBackofficeEditor;
use App\Filament\Backoffice\Resources\Events\EventResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

final class CreateEvent extends CreateRecord
{
    protected static string $resource = EventResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return app(EventBackofficeEditor::class)->createEvent($data);
    }
}
