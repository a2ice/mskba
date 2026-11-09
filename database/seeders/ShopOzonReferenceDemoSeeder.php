<?php

namespace Database\Seeders;

use App\Modules\Shop\Domain\Models\ShopAttribute;
use App\Modules\Shop\Domain\Models\ShopCategory;
use App\Modules\Shop\Domain\Models\ShopProduct;
use App\Modules\Shop\Domain\Models\ShopTag;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Staging-only, idempotent reference fixture. The original Ozon product page
 * blocks automated access, so NO unverified pictures, prices, stock or material
 * claims are populated. An editor can complete the draft from the real page.
 */
final class ShopOzonReferenceDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('staging', 'testing')) {
            return;
        }

        DB::transaction(function (): void {
            $clothing = ShopCategory::query()->firstOrCreate(
                ['slug' => 'odezhda'],
                [
                    'name' => 'Одежда',
                    'description' => 'Спортивная одежда и экипировка для баскетбола.',
                    'status' => 'published',
                    'sort_order' => 10,
                ],
            );

            $uniform = ShopCategory::query()->firstOrCreate(
                ['slug' => 'forma'],
                [
                    'parent_id' => $clothing->id,
                    'name' => 'Форма',
                    'description' => 'Баскетбольные майки, шорты и комплекты формы.',
                    'status' => 'published',
                    'sort_order' => 10,
                ],
            );

            // Do not unexpectedly reparent an editor-owned category.
            if ((int) $uniform->parent_id !== (int) $clothing->id) {
                throw new \RuntimeException('Shop demo category "forma" already has a different parent.');
            }

            $definitions = [
                // [name, code, type, unit, options, category]
                ['Цвет', 'color', 'text', null, null, $clothing],
                ['Размер', 'size', 'text', null, null, $clothing],
                ['Пол', 'gender', 'select', null, ['Мужской', 'Женский', 'Унисекс', 'Для мальчиков', 'Для девочек'], $clothing],
                ['Бренд', 'brand', 'text', null, null, $clothing],
                ['Материал', 'material', 'text', null, null, $clothing],
                ['Состав материала', 'material-composition', 'text', null, null, $uniform],
                ['Комплектация', 'kit-contents', 'text', null, null, $uniform],
                ['Тип рукава', 'sleeve-type', 'select', null, ['Без рукавов', 'Короткий', 'Длинный'], $uniform],
                ['Сезон', 'season', 'text', null, null, $uniform],
                ['Рост', 'height', 'text', 'см', null, $uniform],
                ['Размер производителя', 'manufacturer-size', 'text', null, null, $uniform],
                ['Страна производства', 'country-of-origin', 'text', null, null, $uniform],
                ['Вид спорта', 'sport', 'text', null, null, $uniform],
            ];

            foreach ($definitions as $order => [$name, $code, $type, $unit, $options, $category]) {
                $attribute = ShopAttribute::query()->firstOrCreate(
                    ['code' => $code],
                    [
                        'name' => $name,
                        'type' => $type,
                        'unit' => $unit,
                        'options' => $options,
                        'sort_order' => $order * 10 + 10,
                    ],
                );

                $category->attributes()->syncWithoutDetaching([$attribute->id]);
            }

            $tag = ShopTag::query()->firstOrCreate(
                ['slug' => 'basketbol'],
                ['name' => 'Баскетбол'],
            );
            $referenceTag = ShopTag::query()->firstOrCreate(
                ['slug' => 'demo'],
                ['name' => 'Демонстрационный товар'],
            );
            $uniform->tags()->syncWithoutDetaching([$tag->id]);

            // The Ozon ID is used as a cross-reference, NOT as the seller's SKU.
            $product = ShopProduct::query()->firstOrCreate(
                ['slug' => 'forma-basketbolnaya-ozon-5041196075'],
                [
                    'name' => 'Форма баскетбольная (образец Ozon)',
                    'sku' => 'OZON-REF-5041196075',
                    'description' => "Черновик тестовой карточки MSKBA.\nИсточник для сверки параметров: https://www.ozon.ru/product/forma-basketbolnaya-5041196075/?sh=OoMrT701mg\nНазвание, фотографии, цвет, состав, размер, цена и складские остатки требуют подтверждения по исходной карточке.",
                    'status' => 'draft',
                    'stock_status' => 'out_of_stock',
                    'stock_quantity' => 0,
                    'images' => [],
                    'sort_order' => 10,
                ],
            );

            $product->categories()->syncWithoutDetaching([$uniform->id]);
            $product->tags()->syncWithoutDetaching([$tag->id, $referenceTag->id]);

            // "Basketball" follows from the supplied product type, not speculation.
            if ($product->wasRecentlyCreated) {
                $sport = ShopAttribute::query()->where('code', 'sport')->firstOrFail();
                $product->attributeValues()->firstOrCreate(
                    ['attribute_id' => $sport->id],
                    ['value' => 'Баскетбол'],
                );
            }
        });
    }
}
