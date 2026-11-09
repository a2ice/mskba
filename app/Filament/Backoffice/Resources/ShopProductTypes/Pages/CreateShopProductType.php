<?php

namespace App\Filament\Backoffice\Resources\ShopProductTypes\Pages;

use App\Filament\Backoffice\Resources\ShopProductTypes\ShopProductTypeResource;
use Filament\Resources\Pages\CreateRecord;

final class CreateShopProductType extends CreateRecord
{
    protected static string $resource = ShopProductTypeResource::class;

    private array $attributeIds = [];

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->attributeIds = (array) ($data['attribute_ids'] ?? []);
        unset($data['attribute_ids']);

        return $data;
    }

    protected function afterCreate(): void
    {
        $this->record->attributes()->sync($this->attributeIds);
    }
}
