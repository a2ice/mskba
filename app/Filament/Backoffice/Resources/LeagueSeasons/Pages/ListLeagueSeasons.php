<?php

namespace App\Filament\Backoffice\Resources\${group}\Pages;

use App\Filament\Backoffice\Resources\${group}\${resource};
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListLeagueSeasons extends ListRecords
{
    protected static string $resource = LeagueSeasonResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Создать сезон')];
    }
}
