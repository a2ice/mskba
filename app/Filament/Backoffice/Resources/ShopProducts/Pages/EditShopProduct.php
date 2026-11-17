<?php

namespace App\Filament\Backoffice\Resources\ShopProducts\Pages;

use App\Filament\Backoffice\Resources\ShopProducts\ShopProductResource;
use App\Modules\Shop\Application\Services\ShopCatalogService;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

final class EditShopProduct extends EditRecord
{
    protected static string $resource = ShopProductResource::class;

    private array $categoryIds = [];

    private array $tagIds = [];

    private array $characteristics = [];

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $categoryIds = $this->record->categories()->pluck('shop_categories.id')->all();
        $data['category_ids'] = $categoryIds;
        $data['tag_ids'] = $this->record->tags()->pluck('shop_tags.id')->all();
        $existing = $this->record->attributeValues()->get(['attribute_id', 'value'])
            ->map(fn ($value): array => ['attribute_id' => $value->attribute_id, 'value' => $value->value])->all();
        $data['characteristics'] = app(ShopCatalogService::class)->characteristicRows(
            $categoryIds, $existing, $this->record->product_type_id ? (int) $this->record->product_type_id : null,
        );
        $data['price_rub'] = $this->record->price_kopecks === null
            ? null : number_format($this->record->price_kopecks / 100, 2, '.', '');
        $data['old_price_rub'] = $this->record->old_price_kopecks === null
            ? null : number_format($this->record->old_price_kopecks / 100, 2, '.', '');

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->categoryIds = (array) ($data['category_ids'] ?? []);
        $this->tagIds = (array) ($data['tag_ids'] ?? []);
        $this->characteristics = (array) ($data['characteristics'] ?? []);
        $data['price_kopecks'] = ShopProductResource::kopecks($data['price_rub'] ?? null);
        $data['old_price_kopecks'] = ShopProductResource::kopecks($data['old_price_rub'] ?? null);
        unset($data['category_ids'], $data['tag_ids'], $data['characteristics'], $data['price_rub'], $data['old_price_rub']);

        return $data;
    }

    protected function afterSave(): void
    {
        $this->record->categories()->sync($this->categoryIds);
        $this->record->tags()->sync($this->tagIds);
        app(ShopCatalogService::class)->syncAttributes($this->record, $this->characteristics);
    }

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
