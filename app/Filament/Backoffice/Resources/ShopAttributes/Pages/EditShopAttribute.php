<?php

namespace App\Filament\Backoffice\Resources\ShopAttributes\Pages;

use App\Filament\Backoffice\Resources\ShopAttributes\ShopAttributeResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

final class EditShopAttribute extends EditRecord
{
    protected static string $resource = ShopAttributeResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
