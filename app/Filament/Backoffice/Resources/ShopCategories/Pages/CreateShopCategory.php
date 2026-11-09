<?php

namespace App\Filament\Backoffice\Resources\ShopCategories\Pages;

use App\Filament\Backoffice\Resources\ShopCategories\ShopCategoryResource;
use Filament\Resources\Pages\CreateRecord;

final class CreateShopCategory extends CreateRecord
{
    protected static string $resource = ShopCategoryResource::class;

    private array $tagIds = [];

    private array $attributeIds = [];

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->tagIds = (array) ($data['tag_ids'] ?? []);
        $this->attributeIds = (array) ($data['attribute_ids'] ?? []);
        unset($data['tag_ids'], $data['attribute_ids']);

        return $data;
    }

    protected function afterCreate(): void
    {
        $this->record->tags()->sync($this->tagIds);
        $this->record->attributes()->sync($this->attributeIds);
    }
}
