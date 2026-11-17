<?php
namespace App\Filament\Backoffice\Resources\PageBuilder\Pages;

use App\Filament\Backoffice\Resources\PageBuilder\BuilderPageResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListBuilderPages extends ListRecords
{
    protected static string $resource = BuilderPageResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Добавить')];
    }
}
