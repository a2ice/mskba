# Shop: catalog-only, product types and filtering

## Data model

- `shop_categories` — adjacency tree (`parent_id`), status, images, optional
  `default_product_type_id`. Its parent relationship determines public
  navigation/visibility **only**, never the product specification.
- `shop_products` — publication, price in kopecks, stock state/quantity,
  images, optional `product_type_id` for legacy compatibility.
- `shop_category_product` — multi-category association, independent of type.
- `shop_product_types` — shared named product types.
- `shop_attribute_product_type` — attributes explicitly assigned to each type.
- `shop_attributes` / `shop_product_attribute_values` — reusable attribute definitions
  and validated values. Product types are the single source of allowed fields.
- Old `shop_attribute_category` records are retained during transition so
  untyped legacy products still expose *direct* category characteristics;
  ancestor inheritance is intentionally removed. New items in Filament require a type.
- Tags use polymorphic `shop_taggables`.

## UI and security

- Backoffice Filament 5 resources: categories, products, types, attributes, tags;
  gated by `manage-content`.
- Public `ShopController` serves /shop, /shop/{category:slug}, /shop/p/{product:slug}.
  It checks published category ancestry and product publication; query filters
  use constrained inputs, relationship queries and pagination (10).
- /shop reuses `layouts.default-category`, with filters initially expanded,
  sticky sidebar/toolbar, mobile modal and cards/list controls. No checkout.
- Main product images are files in Laravel `public` disk, referenced by paths.
  The demo fixture copies the provided screenshot crop there once.

## Staging seed and data ownership

`ShopOzonReferenceDemoSeeder` runs only in staging/testing, is idempotent and
does not reset later editor changes. Price/size/color are grounded in the user's
10.11.2026 screenshot; image is an attributed cropped original, not a generated
product photo. Ozon inventory is not our inventory: use `on_request` with zero
local stock, no imaginary number. Staging CI runs the seeder as www-data to
allow first-time writes to the public storage disk.

Future extension: variant entity (SKU per size/color, stock, prices and galleries)
without coupling specifications to category hierarchy.
