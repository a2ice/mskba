<?php

namespace App\Filament\Backoffice\Resources\ShopCategories\Pages;

use App\Filament\Backoffice\Resources\ShopCategories\ShopCategoryResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

final class EditShopCategory extends EditRecord
{
    protected static string $resource = ShopCategoryResource::class;

    private array $tagIds = [];

    private array $attributeIds = [];

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['tag_ids'] = $this->record->tags()->pluck('shop_tags.id')->all();
        $data['attribute_ids'] = $this->record->attributes()->pluck('shop_attributes.id')->all();

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->tagIds = (array) ($data['tag_ids'] ?? []);
        $this->attributeIds = (array) ($data['attribute_ids'] ?? []);
        unset($data['tag_ids'], $data['attribute_ids']);

        return $data;
    }

    protected function afterSave(): void
    {
        $this->record->tags()->sync($this->tagIds);
        $this->record->attributes()->sync($this->attributeIds);
    }

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
