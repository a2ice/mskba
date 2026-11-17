<?php

namespace App\Filament\Backoffice\Resources\Events\Pages;

use App\Filament\Backoffice\Resources\Events\EventResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListEvents extends ListRecords
{
    protected static string $resource = EventResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Добавить мероприятие')];
    }
}
