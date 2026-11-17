<?php

namespace App\Filament\Backoffice\Resources\ContractTemplates\Pages;

use App\Filament\Backoffice\Resources\ContractTemplates\ContractTemplateResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListContractTemplates extends ListRecords
{
    protected static string $resource = ContractTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Создать шаблон')];
    }
}
