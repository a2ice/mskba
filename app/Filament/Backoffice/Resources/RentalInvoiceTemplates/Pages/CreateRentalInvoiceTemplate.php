<?php

namespace App\Filament\Backoffice\Resources\RentalInvoiceTemplates\Pages;

use App\Filament\Backoffice\Resources\RentalInvoiceTemplates\RentalInvoiceTemplateResource;
use Filament\Resources\Pages\CreateRecord;

final class CreateRentalInvoiceTemplate extends CreateRecord
{
    protected static string $resource = RentalInvoiceTemplateResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return RentalInvoiceTemplateResource::normalizedData($data);
    }
}
