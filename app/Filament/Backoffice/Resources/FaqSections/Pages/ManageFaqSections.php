<?php

namespace App\Filament\Backoffice\Resources\FaqSections\Pages;

use App\Filament\Backoffice\Resources\FaqSections\FaqSectionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

final class ManageFaqSections extends ManageRecords
{
    protected static string $resource = FaqSectionResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
