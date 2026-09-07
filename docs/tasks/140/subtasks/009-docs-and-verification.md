# 140/009 — Документация и итоговая проверка

Статус: частично выполнено; executable verification ожидает PR CI и ручной smoke-check.

## Документация

Обновлено:

- `docs/specification/location.md` — City/District, Address FK, Yandex resolver, seeding и admin geography;
- `docs/specification/homepage-event-discovery.md` — полный пятишаговый homepage discovery flow;
- task-документация `140/001..008`.

## Статический review

Проверены:

- актуальный `main` и отсутствие отставания `feature/140`;
- additive migration для `cities`, `districts`, `addresses.city_id`, `addresses.district_id`;
- модели/relations/factories и production-safe seeding;
- provider-neutral geography resolver;
- admin CRUD и ограничения целостности;
- публичные homepage routes;
- Event/Tournament discovery read model;
- frontend import/lifecycle порядка homepage wizard.

Во время review найден lifecycle-дефект discovery: `home-event-discovery.js` статически инициализировался до того, как `home-flow-navigation.js` создавал footer при `modal:opened`. В результате модуль мог завершить инициализацию без финального шага. Исправлено ленивой загрузкой через `home-event-discovery-loader.js` после монтирования navigation DOM.

## Автоматические проверки

CI проекта запускается на `pull_request -> main` и выполняет:

1. `composer install`;
2. подготовку Laravel окружения;
3. `docker compose ... config --quiet`;
4. `php artisan test`;
5. `npm ci --ignore-scripts`;
6. `npm run build`.

На feature push CI не запускается, поэтому до создания PR executable checks отсутствуют.

## Обязательный smoke-check после успешного CI

Desktop и mobile:

- открыть `Мероприятия -> Найти`;
- убедиться, что основной progress содержит 5 шагов;
- проверить, что выбор типа не вызывает auto-advance;
- пройти `Тип -> Параметры -> Где -> Когда -> Результаты`;
- проверить `Не важно` и `Пропустить`;
- проверить game format/recruitment и tournament enrollment policy;
- проверить City/District для Москвы и Химок;
- проверить метро, улицу и predictive venue selector;
- убедиться, что изменение улицы очищает выбранную площадку;
- проверить browser geolocation success/failure и точный fallback-текст;
- проверить диапазон дат сегодня -> +7 дней и защиту `date_to >= date_from`;
- проверить loading overlay, empty-state, error-state, `Обновить`;
- проверить `Назад` с результатов и повторный поиск;
- закрыть popup на результатах и открыть снова — wizard должен стартовать в обычном состоянии;
- проверить popup `Площадки`: общий footer `Назад / Далее` не должен ломать существующий поиск/добавление.

## Состояние перед PR

- реализация 001–008 завершена;
- документация завершена;
- `feature/140` не должна отставать от `main` перед созданием PR;
- PR и merge выполняются только после отдельного подтверждения пользователя.
