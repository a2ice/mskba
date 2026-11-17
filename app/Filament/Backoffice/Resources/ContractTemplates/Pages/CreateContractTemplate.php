<?php

namespace App\Filament\Backoffice\Resources\ContractTemplates\Pages;

use App\Filament\Backoffice\Resources\ContractTemplates\ContractTemplateResource;
use Filament\Resources\Pages\CreateRecord;

final class CreateContractTemplate extends CreateRecord
{
    protected static string $resource = ContractTemplateResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        ContractTemplateResource::validateInput($data);

        return $data;
    }
}
