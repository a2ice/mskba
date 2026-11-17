<?php

namespace App\Filament\Backoffice\Resources\RentalInvoiceTemplates\Pages;

use App\Filament\Backoffice\Resources\RentalInvoiceTemplates\RentalInvoiceTemplateResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListRentalInvoiceTemplates extends ListRecords
{
    protected static string $resource = RentalInvoiceTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
