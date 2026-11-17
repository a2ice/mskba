<?php

namespace App\Filament\Backoffice\Resources\RentalInvoiceTemplates\Pages;

use App\Filament\Backoffice\Resources\RentalInvoiceTemplates\RentalInvoiceTemplateResource;
use Filament\Resources\Pages\EditRecord;

final class EditRentalInvoiceTemplate extends EditRecord
{
    protected static string $resource = RentalInvoiceTemplateResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        // Immutable template scope, so an already issued invoice keeps its origin.
        $data['scope'] = $this->getRecord()->scope;

        return RentalInvoiceTemplateResource::normalizedData($data);
    }
}
