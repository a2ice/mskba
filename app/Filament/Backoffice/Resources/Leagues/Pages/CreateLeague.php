<?php

namespace App\Filament\Backoffice\Resources\Leagues\Pages;

use App\Filament\Backoffice\Resources\Leagues\BackofficeLeagueEditor;
use App\Filament\Backoffice\Resources\Leagues\LeagueResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

final class CreateLeague extends CreateRecord
{
    protected static string $resource = LeagueResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return app(BackofficeLeagueEditor::class)->create($data);
    }
}
