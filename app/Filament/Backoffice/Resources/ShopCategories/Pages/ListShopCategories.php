<?php

namespace App\Filament\Backoffice\Resources\ShopCategories\Pages;

use App\Filament\Backoffice\Resources\ShopCategories\ShopCategoryResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListShopCategories extends ListRecords
{
    protected static string $resource = ShopCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
