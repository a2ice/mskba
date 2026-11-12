# 316 — Production Content & SEO Migration (MSKBA)

Статус: **в работе, PR в `dev`; production не изменять**. Родительская задача — выполнена только после принятия всех этапов и post-release мониторинга.

## Цель
Заменить текущую реализацию `mskba.ru` на новую (`dev.mskba.ru`) **без потери** актуальных production-данных, прежних индексируемых адресов, SEO-атрибутов, медиа, Яндекс.Метрики, истории поисковой индексации и внешних ссылок. Новую БД dev не копировать поверх production. Совместимость и полнота контента приоритетнее улучшения метаданных.

## Исходный проверенный аудит (2026-11-12)
- В production sitemap — **51 URL**, в dev sitemap — **20 URL**.
- Совпадают по путям **13**; 38 production-путей отсутствуют в dev sitemap (12 events, 10 venues, 7 feed, 6 teams, 2 tournaments, 1 sections); 7 путей есть только в dev.
- Примеры: production `/feed/naydi-gde-poigrat-ploshchadki-moskvy-na-karte-mskba` и `/venues/15-ploshchadka-metro-akademicheskaya` — 200; те же адреса dev — 404.
- `/feed`, `/teams`, `/coordination`, `/faq/creation/tournaments` на dev сейчас выдают заглушки вместо прежнего содержимого (иногда HTTP 200).
- Тема `mskba_app` не наследует SEO head, Yandex.Metrika и `lang=ru` старой `mskba_dark`. `/venues` в dev не имеет description/canonical.
- На dev `robots.txt` пока разрешает обход и указывает на production sitemap; нужен запрет индексации на уровне host и ответов.
- Текущая `config/seo.php`, `PageSeoResolver`, `PageSeoSetting`, `SeoSitemapController` и Метрический partial — **существуют**. Основа task 280/025 сохранена, но её перенос в app theme не завершён.
- Эти 51 URL **не исчерпывают** индекс Google/Яндекса, логи, старые адреса и глубокие страницы FAQ: нужна инвентаризация шире sitemap.

## Декомпозиция и очередность
1. [001 — Baseline / контентно-URL реестр](subtasks/001-baseline-inventory.md): полный snapshot URL, статусы, SEO и H1; Вебмастер, GSC, Метрика, server access logs, медиа, canonical/alternate.
2. [002 — SEO foundation + изоляция dev](subtasks/002-seo-foundation-and-staging.md): `mskba_app`, metadata, lang, schema, analytics, robots/noindex, тесты.
3. [003 — Совместимость production-данных](subtasks/003-data-migration.md): копия prod с маскировкой PII на закрытый стенд, миграционный dry-run, проверка ссылочной целостности; никаких тестовых dev записей поверх prod.
4. [004 — Content parity и redirect registry](subtasks/004-content-parity-redirects.md): восстановить виджеты/публичный контент, URLs и IDs, 301 только при однозначном соответствии, без soft 404.
5. [005 — Production release gates](subtasks/005-release-gates.md): сверка на prod-shaped данных, backup/restore, контролируемое переключение, auto-smoke/blocker, согласованный rollback.
6. [006 — SEO после релиза](subtasks/006-post-launch.md): Метрика, GSC, Яндекс Вебмастер; проверки 1/7/30 дней.

## Инварианты / запреты
- Только код, документация и dry-run на `feature/316-...` и `dev`. `main`, production, production DB, DNS, Search Console и Яндекс Вебмастер не менять без отдельного решения.
- Любые команды backfill должны быть **идемпотентны**, логируемы, dry-run по умолчанию; не угадывать соответствие сущностей по имени и не присваивать существующие ID без сверки FK/ограничений.
- На production использовать **существующую** БД и медиа, а не базу dev. Проверить rollback с учетом необратимых миграций.
- Dev не должен попадать в индекс; при этом `robots.txt` и `X-Robots-Tag` должны работать независимо от выбранной темы и JS.
- Сохранять успешные публичные URL; неверные canonical, бесконечные редиректы, placeholder-200, 404 indexable URL и потеря Metрика ID — blockers.
- H1/основной контент, Title, Description, keywords, OG, structured data, изображения и даты публикаций проверять на уровне HTML/страницы, не только БД.
- Персонализированный переход авторизованного пользователя из `/` должен быть временным (302/303), для роботов — публичный HTML.
- `keywords` сохраняем для совместимости, но не считаем SEO KPI.
- `[x]` в `docs/tasks.md` только после merge в main, отдельно фиксировать приёмку на dev.

## Критерии приёмки релиза
- 100% всех подтверждённых индексируемых/prod-significant URL: точная страница 200 или проверенный однозначный 301; отсутствуют потерянные страницы и soft-404 заглушки.
- Production snapshots и target после миграции сопоставлены: по entity/URL, SEO metadata, H1, основному тексту, опубликованному статусу, медиа, перелинковке, датам и результатам выборочных запросов.
- Все XML sitemap URLs отдают 200 и canonical на `https://mskba.ru`. Indexation `dev.mskba.ru` отключена.
- Исторический счётчик Яндекс.Метрики сохранён; события/цели/SPA переходы проверены без дублей; GSC + Вебмастер подтверждены.
- Backups восстановимы; миграции проверены на production-shaped копии; preflight/smoke зелёные; стратегия rollback утверждена.

## Результат по фазам
- 2026-11-12: создана задача и начат stage 002 (SEO `mskba_app` + защита dev) и stage 001 (baseline/CLI comparator). **Не считать все стадии завершёнными.**
