<?php

namespace App\Filament\Backoffice\Resources\Tournaments\Pages;

use App\Filament\Backoffice\Resources\Tournaments\TournamentResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListTournaments extends ListRecords
{
    protected static string $resource = TournamentResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Создать турнир')];
    }
}
