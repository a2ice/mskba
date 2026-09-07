# 141 — Закрыть production/regression замечания после Task 140

## Контекст

После merge Task 140 в `main` штатный CI прошёл успешно, однако автоматический PR review выявил три сценария, которые не покрывались существующими проверками:

1. production deploy выполняет `php artisan migrate --force`, но не запускает `GeographySeeder`, поэтому новые справочники `City`/`District` могут остаться пустыми на production;
2. явно завершённый continuous-турнир с `ends_on = null` и заполненным `tournament_closed_at` может ошибочно попадать в будущие диапазоны homepage discovery;
3. homepage location wizard передаёт выбранные город/улицу в dataset predictive selector, но `venues.search` не применяет эти ограничения на сервере. Из-за лимита выдачи валидная площадка может не попасть в первые результаты, а карта может показывать площадки вне выбранной географии.

После первого production smoke-check также зафиксированы две небольшие UI-регрессии footer/tooltip нового wizard-а:

- при скрытом `Пропустить` кнопка `Далее / Готово` занимала центральную grid-колонку вместо правого края;
- question-tooltip внутри карточек выглядел слишком акцентно-оранжевым для вспомогательного элемента.

## Цель

Сделать follow-up hotfix без изменения продуктовой логики Task 140:

- гарантировать production-safe и idempotent инициализацию географии при обычном deploy;
- корректно ограничивать период явно завершённых турниров;
- применять город и улицу к predictive/map поиску площадок на сервере, до `limit`;
- закрепить footer actions по левому/правому краям независимо от наличия `Пропустить`;
- сделать question-tooltip в event wizard нейтральным светло-серым;
- добавить regression tests и повторно прогнать штатный PR CI.

## Решения

### Production geography bootstrap

Не запускать весь `DatabaseSeeder` на production. После `migrate --force` deploy явно запускает только production-safe `Database\\Seeders\\GeographySeeder` с `--force`.

Это сохраняет тестовую изоляцию миграций и использует уже реализованную идемпотентность сидера.

### Closed tournament effective end

Для пересечения диапазонов homepage discovery оба потенциальных ограничения конца считаются значимыми:

- `ends_on`, если задан;
- дата `tournament_closed_at`, если турнир был явно завершён.

Турнир включается только если каждое заданное ограничение конца не раньше `date_from`. Таким образом явное закрытие раньше поискового диапазона исключает open-ended continuous-турнир из будущей выдачи.

### Venue search geography

Существующий `venues.search` расширяется nullable параметрами `city` и `street`.

`VenueSearchCache` хранит нормализуемые структурированные значения `city`/`street` из `Location -> Address`, а `SearchVenuesHandler` фильтрует документы до сортировки и `limit`.

`venue-selector.js` и `venue-selector-metro.js` читают уже существующие `data-location-city-filter` / `data-location-street-filter` dataset значения homepage location wizard и передают их в backend как отдельные параметры. Это применяется и к predictive dropdown, и к map-загрузке.

### Wizard footer alignment

Shared footer остаётся трёхколоночным (`Назад / Пропустить / Далее`), но каждому action явно назначается grid-column. Поэтому скрытый `Пропустить` больше не сдвигает `Далее / Готово` в центр: back всегда прижат слева, next/final action — справа.

### Wizard tooltip tone

Глобальный tooltip-компонент не меняется. Только `.home-event-choice__help` внутри `[data-home-flow="event"]` получает локальный нейтральный серый border/background/text и такой же нейтральный focus/hover state.

## Проверки

- feature test: явно закрытый open-ended continuous tournament не попадает в будущий discovery range;
- feature test: `venues.search` применяет `city + street` до limit и не возвращает площадку из другой географии;
- существующий полный `php artisan test`;
- `npm run build`;
- ручной smoke-check footer alignment и tooltip tone на desktop/mobile;
- PR CI перед merge.

## Ограничения

- Task 141 не меняет структуру шагов и бизнес-логику wizard;
- не добавляет новый справочник или отдельную географическую сущность;
- не запускает общий production seeding;
- merge в `main` выполняется только после отдельного подтверждения пользователя.