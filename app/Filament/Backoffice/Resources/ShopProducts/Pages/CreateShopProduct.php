<?php

namespace App\Filament\Backoffice\Resources\ShopProducts\Pages;

use App\Filament\Backoffice\Resources\ShopProducts\ShopProductResource;
use App\Modules\Shop\Application\Services\ShopCatalogService;
use Filament\Resources\Pages\CreateRecord;

final class CreateShopProduct extends CreateRecord
{
    protected static string $resource = ShopProductResource::class;

    private array $categoryIds = [];

    private array $tagIds = [];

    private array $characteristics = [];

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->categoryIds = (array) ($data['category_ids'] ?? []);
        $this->tagIds = (array) ($data['tag_ids'] ?? []);
        $this->characteristics = (array) ($data['characteristics'] ?? []);
        $data['price_kopecks'] = ShopProductResource::kopecks($data['price_rub'] ?? null);
        $data['old_price_kopecks'] = ShopProductResource::kopecks($data['old_price_rub'] ?? null);
        unset($data['category_ids'], $data['tag_ids'], $data['characteristics'], $data['price_rub'], $data['old_price_rub']);

        return $data;
    }

    protected function afterCreate(): void
    {
        $this->record->categories()->sync($this->categoryIds);
        $this->record->tags()->sync($this->tagIds);
        app(ShopCatalogService::class)->syncAttributes($this->record, $this->characteristics);
    }
}
