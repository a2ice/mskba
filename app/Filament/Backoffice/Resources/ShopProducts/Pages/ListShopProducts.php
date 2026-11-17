<?php

namespace App\Filament\Backoffice\Resources\ShopProducts\Pages;

use App\Filament\Backoffice\Resources\ShopProducts\ShopProductResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListShopProducts extends ListRecords
{
    protected static string $resource = ShopProductResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
