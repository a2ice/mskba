<?php

namespace Database\Seeders;

use App\Modules\Shop\Domain\Models\ShopAttribute;
use App\Modules\Shop\Domain\Models\ShopCategory;
use App\Modules\Shop\Domain\Models\ShopProduct;
use App\Modules\Shop\Domain\Models\ShopProductType;
use App\Modules\Shop\Domain\Models\ShopTag;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Staging-only fixture derived from the user's screenshot of Ozon item 5041196075.
 * Repeated deploys never overwrite an already edited product or media.
 */
final class ShopOzonReferenceDemoSeeder extends Seeder
{
    public function run(): void
    {
        // APP_ENV on dev is deployment-dependent. Guard by the exact staging URL;
        // test runs are explicitly allowed; production URL can never be seeded.
        if (! app()->environment('testing') && config('app.url') !== 'https://dev.mskba.ru') {
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
            if ((int) $uniform->parent_id !== (int) $clothing->id) {
                throw new \RuntimeException('Existing shop category "forma" has a different parent.');
            }

            $type = ShopProductType::query()->firstOrCreate(
                ['code' => 'basketball-uniform'],
                ['name' => 'Баскетбольная форма',
                    'description' => 'Комплект баскетбольной одежды: майка и шорты.',
                    'sort_order' => 10],
            );
            if ($uniform->default_product_type_id === null) {
                $uniform->update(['default_product_type_id' => $type->id]);
            }

            $definitions = [
                ['Цвет', 'color', 'text', null, null],
                ['Размер', 'size', 'text', null, null],
                ['Пол', 'gender', 'select', null, ['Мужской', 'Женский', 'Мужской, Женский', 'Унисекс']],
                ['Бренд', 'brand', 'text', null, null],
                ['Материал', 'material', 'text', null, null],
                ['Состав материала', 'material-composition', 'text', null, null],
                ['Комплектация', 'kit-contents', 'text', null, null],
                ['Тип рукава', 'sleeve-type', 'select', null, ['Без рукавов', 'Короткий', 'Длинный']],
                ['Сезон', 'season', 'text', null, null],
                ['Рост', 'height', 'text', 'см', null],
                ['Размер производителя', 'manufacturer-size', 'text', null, null],
                ['Страна производства', 'country-of-origin', 'text', null, null],
                ['Вид спорта', 'sport', 'text', null, null],
                ['Тип одежды', 'clothing-type', 'text', null, null],
            ];

            $byCode = [];
            foreach ($definitions as $order => [$name, $code, $attributeType, $unit, $options]) {
                $attribute = ShopAttribute::query()->firstOrCreate(
                    ['code' => $code],
                    ['name' => $name, 'type' => $attributeType, 'unit' => $unit,
                        'options' => $options, 'sort_order' => $order * 10 + 10],
                );
                if ($code === 'gender' && $attribute->type === 'select'
                    && ! in_array('Мужской, Женский', (array) $attribute->options, true)) {
                    $attribute->update(['options' => array_values(array_unique([
                        ...(array) $attribute->options, 'Мужской, Женский',
                    ]))]);
                }
                $type->attributes()->syncWithoutDetaching([$attribute->id]);
                $byCode[$code] = $attribute;
            }

            $basketball = ShopTag::query()->firstOrCreate(
                ['slug' => 'basketbol'], ['name' => 'Баскетбол'],
            );
            $demo = ShopTag::query()->firstOrCreate(
                ['slug' => 'demo'], ['name' => 'Демонстрационный товар'],
            );
            $uniform->tags()->syncWithoutDetaching([$basketball->id]);

            $product = ShopProduct::query()->firstOrCreate(
                ['slug' => 'forma-basketbolnaya-ozon-5041196075'],
                [
                    'name' => 'Форма баскетбольная (образец Ozon)',
                    'sku' => 'OZON-REF-5041196075',
                    'description' => 'Черновик по ссылке Ozon 5041196075.',
                    'status' => 'draft', 'stock_status' => 'on_request',
                    'stock_quantity' => 0, 'images' => [], 'sort_order' => 10,
                ],
            );
            $product->categories()->syncWithoutDetaching([$uniform->id]);
            $product->tags()->syncWithoutDetaching([$basketball->id, $demo->id]);

            if ($product->product_type_id === null) {
                $product->update(['product_type_id' => $type->id]);
            }

            $photoPath = 'shop/products/ozon-5041196075.webp';
            $source = database_path('seeders/assets/shop/ozon-5041196075.webp');
            if (is_file($source) && ! Storage::disk('public')->exists($photoPath)) {
                Storage::disk('public')->put($photoPath, file_get_contents($source));
            }

            // Complete ONLY our untouched starter draft. Never reset admin-edited entries.
            $isStarter = $product->status === 'draft'
                && $product->name === 'Форма баскетбольная (образец Ozon)'
                && $product->price_kopecks === null;
            if ($isStarter) {
                $product->update([
                    'name' => 'Форма баскетбольная',
                    'description' => "Комплект для игры в баскетбол: майка без рукавов и шорты.\n"
                        ."Цвет: чёрный с белой отделкой и голубым акцентом на плече.\n"
                        .'Данные и цена взяты со скриншота Ozon от 10.11.2026. '
                        .'Специальная цена 967 ₽ доступна на Ozon с банками-партнёрами; '
                        ."базовая цена в карточке — 1 018 ₽. Наличие у MSKBA не подтверждено.\n"
                        .'Источник: https://www.ozon.ru/product/forma-basketbolnaya-5041196075/?sh=OoMrT701mg',
                    'status' => 'published',
                    'stock_status' => 'on_request',
                    'stock_quantity' => 0,
                    'price_kopecks' => 101800,
                    'old_price_kopecks' => 265100,
                    'images' => is_file($source) ? [$photoPath] : [],
                ]);

                $values = [
                    'color' => 'Чёрный',
                    'size' => '58 RU',
                    'gender' => 'Мужской, Женский',
                    'kit-contents' => 'Майка и шорты',
                    'sleeve-type' => 'Без рукавов',
                    'season' => 'На любой сезон',
                    'sport' => 'Баскетбол',
                    'clothing-type' => 'Форма баскетбольная',
                ];
                foreach ($values as $code => $value) {
                    $product->attributeValues()->updateOrCreate(
                        ['attribute_id' => $byCode[$code]->id], ['value' => $value],
                    );
                }
            }
        });
    }
}
