<?php
namespace App\Filament\Backoffice\Resources\PageBuilder\Pages;

use App\Filament\Backoffice\Resources\PageBuilder\BuilderTemplateResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListBuilderTemplates extends ListRecords
{
    protected static string $resource = BuilderTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Добавить')];
    }
}
