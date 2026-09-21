# 218 — Декомпозировать web routes по bounded contexts и добавить route integrity checks

## Контекст

Основной `routes/web.php` вырос до примерно 861 строки / 65 КБ и содержит около
385 вызовов `Route::`, 80 controller imports и несколько крупных предметных
групп одновременно.

При этом приложение уже поддерживает более правильную модель: в
`bootstrap/app.php` явно подключаются отдельные route-файлы
`legal.php`, `event-wizard.php`, `game-live.php`,
`game-recruitment.php`, `acquisition.php`, `feed.php` и другие.

Следовательно, новую систему загрузки придумывать не нужно — нужно последовательно
довести существующую декомпозицию.

## Найденная проблема

В `routes/web.php` всё ещё зарегистрированы старые маршруты `/news` с именами
`news.index` и `news.show`.

Одновременно `routes/feed.php` после Task 096 регистрирует:

- legacy `/news` как `legacy.news.index/show`;
- актуальные `/feed` как `news.index/show`.

То есть текущая корректность `route('news.index') -> /feed` зависит от порядка
подключения route-файлов и повторной регистрации одинаковых route names.
Это нужно убрать в рамках рефакторинга и закрепить тестом.

## Цель

Сделать route registry читаемым по предметным контурам, сохранив без изменений
публичные URL, route names, middleware, model binding и порядок совпадения
маршрутов.

Рефакторинг routes не является механизмом выбора тестов для CI. Это отдельная
задача архитектурной чистоты и защиты route contract.

## Принципы

### 1. Группировать по bounded context, а не глобально по алфавиту

Глобальная сортировка всех URL по алфавиту нежелательна: порядок маршрутов может
быть функционально значимым, особенно рядом со статическими путями и
`/{alias}` / `/{id}` catch-all сегментами.

Внутри предметного файла порядок:

1. статические/special routes;
2. collection routes;
3. create/search/helper endpoints;
4. parameterized entity routes;
5. nested routes;
6. наиболее общий dynamic route — в конце.

### 2. Явный порядок подключения

Сохранить explicit список route-файлов в `bootstrap/app.php`.
Не переходить на неупорядоченный filesystem glob.

Так видно, какой файл регистрируется раньше, и можно контролировать пересечения
URL.

### 3. Предлагаемая декомпозиция

Точные имена файлов можно уточнить при переносе, но целевой смысл примерно такой:

- `routes/portal.php` — welcome/site summary и общие portal endpoints;
- `routes/identity.php` — auth, participants, public profile/account identity;
- `routes/admin.php` + крупные admin-specific файлы;
- `routes/venues.php`;
- `routes/venue-booking.php`;
- `routes/events.php`;
- `routes/tournaments.php`;
- `routes/teams.php`;
- `routes/sports-sections.php`;
- `routes/coordination.php`;
- `routes/telegram.php`;
- `routes/location.php`;
- `routes/pricing.php`.

Уже существующие специализированные файлы не объединять обратно в монолит без
причины.

Для admin, где сейчас десятки маршрутов, допустимо оставить отдельные
`admin-content.php`, `admin-acquisition.php`, `admin-venues.php`,
`admin-pricing.php` вместо одного нового большого admin-файла.

### 4. `routes/web.php` после рефакторинга

Файл должен перестать быть свалкой всех новых маршрутов. Возможны два нормальных
варианта:

- оставить в нём только несколько действительно общих web endpoints;
- либо оставить минимальный compatibility-файл, если всю предметную регистрацию
  уже выполняет explicit список в `bootstrap/app.php`.

Новые bounded-context routes после Task 218 должны добавляться сразу в
соответствующий файл, а не в конец `web.php`.

### 5. Route integrity checks

Добавить автоматическую проверку route contract:

- все route names уникальны;
- `php artisan route:list` успешно строится;
- route cache smoke выполняется без коллизий:
  `php artisan route:cache` → `php artisan route:clear`;
- ключевые именованные URL до/после рефакторинга не меняются;
- legacy redirects (`/news -> /feed` и аналогичные) остаются явными.

Route cache smoke полезен и для CI, потому что duplicate names могут долго
оставаться незаметными, если приложение полагается на порядок регистрации.

### 6. Перенос без функциональных изменений

Task 218 — refactoring task. В одном переносе не следует одновременно
переименовывать URL, route names или менять permissions.

Исключение — удаление очевидной дублирующей регистрации, если целевой contract
уже зафиксирован тестом (как Task 096 для feed).

## Последовательность

1. снять snapshot текущего `route:list`;
2. добавить/усилить route-contract tests;
3. убрать подтверждённые duplicate registrations;
4. переносить один bounded context за раз;
5. после каждого этапа сравнивать route names + URI + methods + middleware;
6. прогнать профильные tests;
7. финальный полный CI.

## Критерии готовности

- `routes/web.php` существенно уменьшен и больше не является основным реестром
  большинства bounded contexts;
- маршруты легко находятся по предметному файлу;
- explicit load order сохранён в `bootstrap/app.php`;
- route names не дублируются;
- `news.index/show` однозначно указывают на `/feed`, а `/news` остаётся
  только legacy redirect flow;
- route cache smoke проходит;
- до/после snapshot не показывает случайных изменений route contract;
- полный CI green.

## Статус

Запланировано.
