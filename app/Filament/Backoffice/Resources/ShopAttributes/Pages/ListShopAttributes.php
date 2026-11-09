<?php

namespace App\Filament\Backoffice\Resources\ShopAttributes\Pages;

use App\Filament\Backoffice\Resources\ShopAttributes\ShopAttributeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListShopAttributes extends ListRecords
{
    protected static string $resource = ShopAttributeResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
