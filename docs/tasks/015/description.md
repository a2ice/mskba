# 015 - Добавить доменный контекст Location для адресов и метро

## Оригинальное описание

Пользователь попросил разработать доменный контекст/агрегат "локация", который включает адрес и метро и будет использоваться площадками, событиями и другими будущими сущностями.

Дополнительные решения:

- метро связывается с `Location`, а не напрямую с `Address`, `Venue` или будущими событиями;
- для площадок добавляется fallback-поле сырого адреса на случай, если структурированной локации еще нет.

## Подробное описание

Нужно добавить отдельный bounded context `App\Modules\Location`.

Модель:

- `Location` - агрегатный корень физического места;
- `Address` - структурированный адрес;
- `MetroLine` - справочник веток метро;
- `MetroStation` - справочник станций метро;
- `location_metro_station` - связь локации с ближайшими/релевантными станциями метро.

Связь с площадками:

- `venues.location_id` nullable foreign key на `locations`;
- `venues.raw_address` nullable string/text fallback для сырого адреса;
- `Venue` получает relation `location()`.

Требования:

- не связывать метро напрямую с площадками или событиями;
- не связывать метро напрямую с адресами;
- сохранить возможность площадки существовать без структурированной локации;
- обновить DTO/форму/страницы площадок, чтобы сырой адрес можно было сохранить и показать;
- обновить продуктовую и техническую документацию.

## Затронутые файлы

- `app/Modules/Location`;
- `database/migrations`;
- `app/Modules/Venue`;
- `resources/themes/mskba_dark/views/pages/account/venue-create.blade.php`;
- `resources/themes/mskba_dark/views/pages/account/venue.blade.php`;
- `resources/themes/mskba_dark/views/pages/account/venues.blade.php`;
- `resources/themes/mskba_dark/views/pages/venues/index.blade.php`;
- `resources/themes/mskba_dark/views/pages/venues/show.blade.php`;
- `docs/project.md`;
- `docs/specification.md`;
- `docs/specification/location.md`.

## Проверки

- `php artisan test --filter Venue`;
- `php artisan test tests/Feature/Location/LocationModelTest.php`;
- `npm run build`, если меняются view/assets.

## Результат

Добавлен bounded context `App\Modules\Location`.

Реализованы модели:

- `Location`;
- `Address`;
- `MetroLine`;
- `MetroStation`.

Добавлены таблицы:

- `addresses`;
- `locations`;
- `metro_lines`;
- `metro_stations`;
- `location_metro_station`.

Метро связано с `Location` через pivot `location_metro_station`, а не напрямую с адресом, площадкой или будущими событиями.

Площадки обновлены:

- добавлен nullable `venues.location_id`;
- добавлен nullable `venues.raw_address`;
- `Venue` получил relation `location()`;
- создание площадки принимает и сохраняет `raw_address`;
- списки и карточки площадок показывают сырой адрес, если он указан.

Дополнительно исправлено имя Gate для создания площадки: добавлен `add_venue`, который уже использовался в request/controller; старое имя `venue-create-venue` оставлено как alias.

Обновлены:

- `docs/project.md`;
- `docs/specification.md`;
- `docs/specification/location.md`;
- `app/Modules/Venue/README.md`;
- `app/Modules/Location/README.md`.

Проверки:

- `find app/Modules/Location -name '*.php' -print0 | xargs -0 -n1 php -l` - пройден;
- `php artisan test tests/Feature/Location/LocationModelTest.php tests/Feature/Venue/VenueRawAddressTest.php --filter '/LocationModelTest|VenueRawAddressTest/'` - пройден;
- `php artisan test --filter Venue` - пройден;
- `npm run build` - пройден.
