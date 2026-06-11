# Location

## Оглавление

- [Назначение](#назначение)
- [Граница контекста](#граница-контекста)
- [Модель](#модель)
- [Связь с площадками](#связь-с-площадками)
- [Метро](#метро)
- [Развитие модели](#развитие-модели)

## Назначение

`Location` - отдельный доменный контекст для физических локаций, структурированных адресов и метро.

Контекст нужен не только площадкам, но и будущим событиям, тренировкам, командам, компаниям и другим сущностям, у которых может быть физическое место.

## Граница контекста

Код контекста расположен в `App\Modules\Location`.

Текущие доменные модели:

- `Location`;
- `Address`;
- `MetroLine`;
- `MetroStation`.

Текущие таблицы:

- `locations`;
- `addresses`;
- `metro_lines`;
- `metro_stations`;
- `location_metro_station`.

## Модель

`Location` является агрегатным корнем физического места.

`Address` хранит структурированный адрес:

- `postal_code`;
- `city`;
- `street`;
- `building`;
- `latitude`;
- `longitude`;
- `full_address`.

Координаты находятся в `addresses`, потому что это свойство конкретной географической точки адреса.

## Связь с площадками

`Venue` связан с `Location` через nullable FK:

- `venues.location_id`.

Площадка также хранит fallback-адрес:

- `venues.raw_address`.

`raw_address` нужен, когда структурированная локация еще не создана или адрес получен в сыром виде. Отображение адреса должно использовать структурированную `location`, когда она есть; если `location_id` отсутствует, можно показывать `raw_address`.

При создании площадки форма передает данные локации вложенным объектом:

- `location.raw_address`;
- `location.city`;
- `location.street`;
- `location.building`;
- `location.postal_code`;
- `location.latitude`;
- `location.longitude`;
- `location.metro_station_ids`.

`CreateVenueRequest` преобразует эти поля в `CreateLocationDTO`. `CreateAccountVenueHandler` оркестрирует создание площадки и вызывает `CreateLocationHandler`, который создает `Address`, `Location` и связи `location_metro_station`. Сырой адрес сохраняется в `addresses.full_address`, а `venues.raw_address` продолжает заполняться как fallback для отображения и обратной совместимости.

Адрес площадки вводится через предиктивное поле. Фронтенд обращается только к backend endpoint:

- `GET /integrations/address-suggest?query=...`.

Endpoint защищен `auth` и `throttle:30,1`, не отдает клиенту ключ Yandex и возвращает нормализованный JSON:

- `label`;
- `country`;
- `city`;
- `street`;
- `building`;
- `postal_code`;
- `latitude`;
- `longitude`;
- `has_house`;
- `metro_station_ids`;
- `metro_station_labels`.

Настройки интеграции находятся в `config/integrations.php` и задаются через env:

- `ADDRESS_SUGGEST_PROVIDER`;
- `ADDRESS_DEFAULT_COUNTRY`;
- `ADDRESS_DEFAULT_CITY`;
- `ADDRESS_SUGGEST_LIMIT`;
- `YANDEX_MAPS_API_KEY`.

Если ключ Yandex не задан или внешний API недоступен, endpoint возвращает пустой список подсказок, а форма продолжает работать как ручной ввод адреса. В этом случае `CreateLocationHandler` создает fallback-address с `city = ADDRESS_DEFAULT_CITY` и `full_address = location.raw_address`.

## Метро

Метро связывается с `Location`, а не с `Address` и не напрямую с `Venue` или будущими событиями.

Причины:

- станция метро является справочником и переиспользуется многими локациями;
- один адрес может соответствовать разным объектам на территории с разной фактической близостью к станциям;
- разные сущности должны получать метро через общий `Location`, а не дублировать связи в своих таблицах.

Связь реализуется через pivot `location_metro_station`:

- `location_id`;
- `metro_station_id`;
- `distance_meters`;
- `walking_time_minutes`.

`MetroLine` хранит ветку метро, `MetroStation` принадлежит ветке. Станции с одинаковым названием на разных ветках не схлопываются в один option на backend-уровне: значение выбора - это конкретный `metro_stations.id`, а UI должен различать такие станции по ветке и цвету линии.

Yandex-подсказки могут вернуть названия ближайших станций метро. `AddressSuggestService` нормализует эти названия и сопоставляет их с локальным справочником `metro_stations`. Если по названию станция не найдена, но у адреса есть координаты, сервис выбирает ближайшую локальную станцию по координатам. Автоподбор метро только выставляет значения по умолчанию: пользователь может вручную изменить выбранные станции перед отправкой формы.

## Развитие модели

Следующие шаги должны добавляться отдельными задачами:

- импорт или ручное наполнение справочника московского метро;
- подключение `Location` к событиям, тренировкам, командам и другим сущностям.
