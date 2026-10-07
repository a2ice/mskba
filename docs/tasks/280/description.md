# 280 — SEO-аудит и техническая SEO-база MSKBA

## Оригинальное описание

Проверить внутреннее SEO MSKBA и подготовить портал к системному контентному продвижению:
метатеги, title, alt, canonical, sitemap, structured data и остальные технические основы.
Дальнейшие этапы программы — публикация в VK/Instagram, управляемые источники,
автоматический сбор новостей, AI-редактура и генерация обложек.

## Аудит до изменений

Проверка актуального `main` и production на 2026-10-01 показала:

- базовый layout уже выводит `title`, `description`, `canonical` и Open Graph;
- для материалов есть отдельные `meta_title`, `meta_description`, `meta_keywords`;
- для venue/event/team существует `PageSeoResolver`;
- legacy `/news` корректно делает 301 на канонический `/feed`;
- главная production-страница отдавала `lang="en"`, хотя основной контент русскоязычный;
- title главной дублировал бренд: `MSKBA — баскетбол в Москве · MSKBA`;
- у главной и `/feed` отсутствовал meta description;
- default social preview image и Twitter/X Card отсутствовали;
- `/sitemap.xml` возвращал 404;
- `robots.txt` не содержал ссылку на sitemap;
- в ключевых карточках контентные изображения имели пустой `alt`;
- JSON-LD для сайта и новостных материалов отсутствовал;
- canonical у пагинации `/feed?page=N` схлопывался до `/feed`;
- `https://www.mskba.ru` и `https://mskba.ru` одновременно отдавали 200 и self-canonical, поэтому поисковик видел два хоста.

Google Search Console через подключённый GSC Wizard проверить не удалось: интеграция
сообщает об окончании trial/подписки. После восстановления доступа нужен контроль
индексации, URL Inspection, Core Web Vitals и фактических поисковых запросов.

## Цель этапа

Сделать безопасный технический фундамент, который можно отдельно выкатить в production
до запуска автопарсинга и мультиканальной редакции.

## Реализация

- централизованные SEO defaults для основных публичных каталогов;
- корректный русский `html lang`;
- Open Graph с default image, `og:site_name`, `og:locale`;
- Twitter/X Card;
- поддержка `meta robots`, `rel=prev/next` и дополнительных JSON-LD блоков;
- `SportsOrganization` + `WebSite` JSON-LD на главной;
- `NewsArticle` JSON-LD на странице материала;
- корректный canonical для страниц пагинации ленты;
- динамический `/sitemap.xml` для основных каталогов и публичных сущностей;
- ссылка на sitemap из `robots.txt`;
- осмысленные alt-тексты для новостей и ключевых динамических карточек главной.

## Canonical host: www → non-www

Первая реализация редиректа `www.mskba.ru → mskba.ru` была добавлена во внутренний
Docker Nginx и после production deploy привела к `ERR_TOO_MANY_REDIRECTS` через внешний
host-level HTTPS reverse proxy. Эта часть была откатана hotfix PR #291.

Диагностика production 2026-10-06 подтвердила фактическую схему:

- внешний Ubuntu Nginx завершает TLS на `443`;
- `mskba.ru` и `www.mskba.ru` проксируются на Docker Nginx `127.0.0.1:8000`;
- Docker Nginx должен оставаться HTTP-only и не отвечать за canonical-host redirect;
- production deploy user `deploy` не имеет passwordless sudo, поэтому host-level Nginx
  нельзя безопасно менять из обычного GitHub Actions deploy.

Целевая политика:

- `http://mskba.ru/* → 301 https://mskba.ru/*`;
- `http://www.mskba.ru/* → 301 https://mskba.ru/*`;
- `https://www.mskba.ru/* → 301 https://mskba.ru/*`;
- `https://mskba.ru/* → application response`.

Версионированный host-level конфиг находится в `ops/nginx/mskba-prod.conf`.
Для безопасного применения с backup, `nginx -t`, reload и автоматическим rollback
подготовлен `ops/nginx/apply-mskba-prod.sh`. Проверка всех четырёх вариантов адреса —
`ops/nginx/check-canonical-host.sh`.

## Production hardening sitemap — 2026-10-07

После первого production rollout динамический `/sitemap.xml` начал возвращать 500,
хотя профильный feature-тест и общий CI оставались зелёными. Runtime-диагностика на
production установила точную причину: PHP-FPM работает с `short_open_tag=1`, а первая
строка Blade-шаблона содержала literal `<?xml ... ?>` внутри raw Blade echo. При
компиляции Blade эта строка осталась необработанной в compiled view, после чего PHP
падал с `ParseError: unexpected identifier "version"`.

CI этого не видел, потому что тестовый PHP запускался с другим значением
`short_open_tag`. Одновременно исходный sitemap-тест создавал только опубликованный
`ContentItem`, поэтому ветки Venue/Event/Team/Tournament/SportsSection тоже не были
покрыты production-shaped данными.

Follow-up исправление:

- каждый динамический источник sitemap теперь изолирован: сбой запроса одного источника
  или построения одной записи логируется, но не превращает весь sitemap в HTTP 500;
- канонизированные дубли площадок (`canonical_venue_id != null`) исключаются из sitemap;
- sitemap сортируется детерминированно после дедупликации;
- regression-тест создаёт публичные Venue/Event/Team/Tournament/SportsSection и проверяет
  реальные URL каждого типа;
- XML declaration теперь формируется в обычном PHP-коде контроллера, а не внутри
  Blade, поэтому Blade compiler не видит `<?xml` как short-open-tag;
- CI запускается с `short_open_tag=1`, как production PHP-FPM, чтобы такая разница
  окружений больше не проходила незамеченной;
- production deploy получает обязательный smoke-check `/sitemap.xml`: HTTP 200,
  `application/xml`, корректный `urlset` и хотя бы один canonical URL `mskba.ru`.

Такой fail-soft относится только к динамическим источникам. Статические обязательные
маршруты и финальный XML всё ещё должны строиться корректно; иначе endpoint продолжит
падать и post-deploy smoke остановит workflow.

## Следующие задачи программы

- Task 281 — общий publication layer и адаптеры Telegram/VK/Instagram;
- Task 282 — редактируемый реестр источников и ingestion по расписанию/кнопке;
- Task 283 — AI-редактура, provenance/атрибуция и генерация собственной обложки;
- Task 284 — moderation queue, расписание, дедупликация и аналитика публикаций.

## Контентная политика

Автоматизация не должна превращаться в копирование чужих публикаций. Для внешнего
источника сохраняются original URL, название источника и время получения. На MSKBA
создаётся самостоятельный фактологический пересказ с атрибуцией источника. Чужие
изображения не переиспользуются автоматически без разрешения/лицензии; базовый сценарий —
собственная сгенерированная обложка.

На первом rollout автоматический сбор создаёт редакторский draft. Полностью автоматическую
публикацию следует включать отдельно только для доверенных источников после проверки
дедупликации, качества и стоимости генераций.

## Проверка

- feature tests на sitemap, базовые метаданные, canonical пагинации и NewsArticle JSON-LD;
- профильный PHPUnit-прогон и затем CI;
- после deploy: live fetch главной, `/feed`, материала, `/robots.txt`, `/sitemap.xml`;
- после применения host-level canonical config: проверить все четыре URL-варианта через
  `ops/nginx/check-canonical-host.sh`;
- после восстановления GSC: URL Inspection и отправка sitemap.
