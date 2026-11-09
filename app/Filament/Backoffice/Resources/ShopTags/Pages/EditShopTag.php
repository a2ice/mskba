<?php

namespace App\Filament\Backoffice\Resources\ShopTags\Pages;

use App\Filament\Backoffice\Resources\ShopTags\ShopTagResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

final class EditShopTag extends EditRecord
{
    protected static string $resource = ShopTagResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
