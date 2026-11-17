<?php

namespace App\Filament\Backoffice\Resources\Seasons\Pages;

use App\Filament\Backoffice\Resources\Seasons\SeasonResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListSeasons extends ListRecords
{
    protected static string $resource = SeasonResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Создать сезон')->visible(fn (): bool => SeasonResource::canCreate())];
    }
}
