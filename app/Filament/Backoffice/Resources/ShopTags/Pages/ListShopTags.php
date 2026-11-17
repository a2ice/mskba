<?php

namespace App\Filament\Backoffice\Resources\ShopTags\Pages;

use App\Filament\Backoffice\Resources\ShopTags\ShopTagResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListShopTags extends ListRecords
{
    protected static string $resource = ShopTagResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
