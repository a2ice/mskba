<?php

namespace App\Filament\Backoffice\Resources\Leagues\Pages;

use App\Filament\Backoffice\Resources\Leagues\BackofficeLeagueEditor;
use App\Filament\Backoffice\Resources\Leagues\LeagueResource;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

final class EditLeague extends EditRecord
{
    protected static string $resource = LeagueResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['team_ids'] = $this->getRecord()->teams()->pluck('teams.id')->all();

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return app(BackofficeLeagueEditor::class)->update($record, $data);
    }
}
