<?php

namespace App\Filament\Backoffice\Resources\${group}\Pages;

use App\Filament\Backoffice\Resources\Competitions\CompetitionCatalogEditor;
use App\Filament\Backoffice\Resources\${group}\${resource};
use Filament\Resources\Pages\${parent};
use Illuminate\Database\Eloquent\Model;

final class EditLeague extends EditRecord
{
    protected static string $resource = LeagueResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return app(CompetitionCatalogEditor::class)->updateLeague($record, $data);
    }
}
