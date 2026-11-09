<?php

namespace App\Filament\Backoffice\Resources\ShopCategories\Pages;

use App\Filament\Backoffice\Resources\ShopCategories\ShopCategoryResource;
use Filament\Resources\Pages\CreateRecord;

final class CreateShopCategory extends CreateRecord
{
    protected static string $resource = ShopCategoryResource::class;

    private array $tagIds = [];

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->tagIds = (array) ($data['tag_ids'] ?? []);
        unset($data['tag_ids']);

        return $data;
    }

    protected function afterCreate(): void
    {
        $this->record->tags()->sync($this->tagIds);
    }
}
