<?php

namespace App\Filament\Backoffice\Resources\Seasons\Pages;

use App\Filament\Backoffice\Resources\Seasons\BackofficeSeasonEditor;
use App\Filament\Backoffice\Resources\Seasons\SeasonResource;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

final class EditSeason extends EditRecord
{
    protected static string $resource = SeasonResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['team_ids'] = $this->getRecord()->teams()->pluck('teams.id')->all();

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return app(BackofficeSeasonEditor::class)->update($record, $data);
    }
}
