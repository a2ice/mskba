<?php
namespace App\Filament\Backoffice\Resources\PageBuilder\Pages;

use App\Filament\Backoffice\Resources\PageBuilder\BuilderComponentResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListBuilderComponents extends ListRecords
{
    protected static string $resource = BuilderComponentResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Добавить')];
    }
}
