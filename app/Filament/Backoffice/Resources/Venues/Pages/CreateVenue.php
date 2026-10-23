<?php

namespace App\Filament\Backoffice\Resources\Venues\Pages;

use App\Filament\Backoffice\Resources\Venues\VenueBackofficeEditor;
use App\Filament\Backoffice\Resources\Venues\VenueResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

final class CreateVenue extends CreateRecord
{
    protected static string $resource = VenueResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return app(VenueBackofficeEditor::class)->save($data);
    }
}
