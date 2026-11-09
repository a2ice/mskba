<?php

namespace App\Filament\Backoffice\Resources\ShopProductTypes\Pages;

use App\Filament\Backoffice\Resources\ShopProductTypes\ShopProductTypeResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

final class EditShopProductType extends EditRecord
{
    protected static string $resource = ShopProductTypeResource::class;

    private array $attributeIds = [];

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['attribute_ids'] = $this->record->attributes()->pluck('shop_attributes.id')->all();

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->attributeIds = (array) ($data['attribute_ids'] ?? []);
        unset($data['attribute_ids']);

        return $data;
    }

    protected function afterSave(): void
    {
        $this->record->attributes()->sync($this->attributeIds);
    }

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
