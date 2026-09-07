# 140/009 — Документация и итоговая проверка

Статус: частично выполнено; executable verification ожидает PR CI и ручной smoke-check.

## Документация

Обновлено:

- `docs/project/homepage-event-discovery.md` — продуктовый пятишаговый сценарий поиска на главной;
- `docs/project/events-games-tournaments.md` — ссылка и краткое место homepage discovery в общей продуктовой модели;
- `docs/specification/location.md` — City/District, Address FK, Yandex resolver, seeding и admin geography;
- `docs/specification/homepage-event-discovery.md` — технический контракт пятишагового homepage discovery flow;
- `docs/specification/event-game-tournament-architecture.md` — discovery зафиксирован как read-only проекция, а не новый доменный агрегат;
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
- frontend import/lifecycle порядка homepage wizard;
- изоляция тестового времени в новых feature tests.

Во время review найдены и исправлены три пограничных дефекта:

1. discovery мог статически инициализироваться до готовности общего footer. Финальный слой теперь загружается лениво через `home-event-discovery-loader.js` после открытия popup;
2. повторный `GeographySeeder` мог создать дубликат канонического города/района после ручного изменения его alias. Seed identity теперь ищет существующую запись по исходному alias, name или short_name и никогда не перезаписывает найденные значения;
3. `HomeEventDiscoveryControllerTest` устанавливал глобальный Carbon test clock без явного сброса. Добавлен `tearDown`, чтобы время не протекало в последующие тесты.

## Автоматические проверки

CI проекта запускается на `pull_request -> main` и выполняет:

1. `composer install`;
2. подготовку Laravel окружения;
3. `docker compose ... config --quiet`;
4. `php artisan test`;
5. `npm ci --ignore-scripts`;
6. `npm run build`.

На feature push CI не запускается, а доступный GitHub-инструмент не предоставляет runtime Laravel/Vite, поэтому до создания PR executable checks отсутствуют. Пункт плана с тестами/build нельзя считать выполненным только по статическому review.

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
- проверить popup `Площадки`: общий footer `Назад / Далее` не должен ломать существующий поиск/добавление;
- открыть `/admin/geography`, проверить редактирование City/District и запрет удаления используемых записей.

## Состояние перед PR

- реализация 001–008 завершена;
- продуктовая и техническая документация завершена;
- статический review завершён, найденные дефекты исправлены;
- `feature/140` не должна отставать от `main` перед созданием PR;
- следующий необходимый шаг — PR в `main`, чтобы запустить штатный CI;
- PR и merge выполняются только после отдельного подтверждения пользователя.
