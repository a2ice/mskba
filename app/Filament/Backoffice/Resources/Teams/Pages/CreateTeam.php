<?php

namespace App\Filament\Backoffice\Resources\Teams\Pages;

use App\Filament\Backoffice\Resources\Teams\BackofficeTeamEditor;
use App\Filament\Backoffice\Resources\Teams\TeamResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

final class CreateTeam extends CreateRecord
{
    protected static string $resource = TeamResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return app(BackofficeTeamEditor::class)->create($data);
    }
}
