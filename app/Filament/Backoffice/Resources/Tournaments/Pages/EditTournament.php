<?php

namespace App\Filament\Backoffice\Resources\Tournaments\Pages;

use App\Filament\Backoffice\Resources\Competitions\CompetitionCatalogEditor;
use App\Filament\Backoffice\Resources\Tournaments\TournamentResource;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

final class EditTournament extends EditRecord
{
    protected static string $resource = TournamentResource::class;

    public function hasCombinedRelationManagerTabsWithContent(): bool
    {
        return true;
    }

    public function getContentTabLabel(): ?string
    {
        return 'Настройки турнира';
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return app(CompetitionCatalogEditor::class)->updateTournament($record, $data);
    }
}
