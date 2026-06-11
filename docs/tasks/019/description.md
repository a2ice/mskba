# 019 - Yandex-подсказки адресов и геокодирование для площадок

## Оригинальное описание

Добавить предиктивный ввод адреса площадки через Yandex API.

Пользователь должен вводить адрес в одно поле. Backend должен получать подсказки от Yandex, показывать варианты под полем, при выборе варианта заполнять структурированные данные адреса и подбирать ближайшие станции метро.

Результат должен сохраняться в текущую модель:

- `addresses`;
- `locations`;
- `location_metro_station`;
- `venues.location_id`;
- `venues.raw_address` как fallback/отображаемый сырой адрес.

## Подробное описание

Задача развивает контекст `Location` и форму создания площадки:

- Yandex API вызывается только с backend, чтобы не раскрывать `YANDEX_MAPS_API_KEY` на клиенте;
- endpoint `/integrations/address-suggest` возвращает нормализованные подсказки адреса;
- frontend показывает подсказки в форме площадки и заполняет hidden-поля структурированного адреса;
- метро автоподбирается по названию из Yandex или по ближайшим координатам, но пользователь может изменить выбор вручную;
- сохранение идет через `Location`: создаются `Address`, `Location` и связи `location_metro_station`;
- `venues.raw_address` остается fallback для отображения и сценариев без Yandex.

## Результат

Реализованы:

- конфигурация `config/integrations.php` и env-переменные для Yandex;
- `AddressSuggestProvider` и `YandexAddressSuggestProvider`;
- `AddressSuggestService` с маппингом локальных станций метро;
- `AddressSuggestController` и route `/integrations/address-suggest`;
- расширенный `CreateLocationDTO` и `CreateLocationHandler`;
- сохранение `location_id` при создании площадки;
- предиктивное поле адреса и Tom Select для метро в форме площадки;
- CSS для подсказок адреса и Tom Select;
- документация по Location/Yandex flow;
- feature/unit-тесты для endpoint, provider, service и сохранения location.

## Проверки

- `git diff --check` - пройден;
- `php artisan test tests/Feature/Location tests/Feature/Venue tests/Unit/YandexAddressSuggestProviderTest.php` - пройден, `23 tests`, `76 assertions`;
- `npm run build` - пройден.

Полный `composer test` на момент выполнения падает на существующих admin-тестах из-за `Route [admin.dashboard] not defined`; это не относится к задаче 019.
