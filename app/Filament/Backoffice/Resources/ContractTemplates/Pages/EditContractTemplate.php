<?php

namespace App\Filament\Backoffice\Resources\ContractTemplates\Pages;

use App\Filament\Backoffice\Resources\ContractTemplates\ContractTemplateResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

final class EditContractTemplate extends EditRecord
{
    protected static string $resource = ContractTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        ContractTemplateResource::validateInput($data);

        return $data;
    }
}
