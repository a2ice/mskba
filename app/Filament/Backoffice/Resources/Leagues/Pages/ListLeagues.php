<?php

namespace App\Filament\Backoffice\Resources\Leagues\Pages;

use App\Filament\Backoffice\Resources\Leagues\LeagueResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListLeagues extends ListRecords
{
    protected static string $resource = LeagueResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Создать лигу')->visible(fn (): bool => LeagueResource::canCreate())];
    }
}
