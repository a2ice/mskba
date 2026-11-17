<?php

namespace App\Filament\Backoffice\Resources\Tournaments\Pages;

use App\Filament\Backoffice\Resources\Competitions\CompetitionCatalogEditor;
use App\Filament\Backoffice\Resources\Tournaments\TournamentResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

final class CreateTournament extends CreateRecord
{
    protected static string $resource = TournamentResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return app(CompetitionCatalogEditor::class)->createTournament($data);
    }
}
