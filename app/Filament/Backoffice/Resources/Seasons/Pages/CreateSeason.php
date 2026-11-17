<?php

namespace App\Filament\Backoffice\Resources\Seasons\Pages;

use App\Filament\Backoffice\Resources\Seasons\BackofficeSeasonEditor;
use App\Filament\Backoffice\Resources\Seasons\SeasonResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

final class CreateSeason extends CreateRecord
{
    protected static string $resource = SeasonResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return app(BackofficeSeasonEditor::class)->create($data);
    }
}
