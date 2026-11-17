<?php

namespace App\Filament\Backoffice\Resources\ShopProductTypes\Pages;

use App\Filament\Backoffice\Resources\ShopProductTypes\ShopProductTypeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListShopProductTypes extends ListRecords
{
    protected static string $resource = ShopProductTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
