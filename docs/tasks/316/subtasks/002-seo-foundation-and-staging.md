# 316/002 — SEO layout и non-production indexing

Статус: **реализация в feature branch, требует CI + dev acceptance**.

- Восстановить в `mskba_app`: config-based title/description fallback, canonical, keywords, OG/Twitter, JSON-LD, `html lang=ru`, Яндекс-верификацию, Яндекс.Метрику и noscript, meta-robots.
- Сохранять явно указанные SEO значения выше fallback. Не менять действующую `mskba_dark` без необходимости.
- `X-Robots-Tag: noindex, nofollow` для неканонических хостов на уровне Laravel; dev `robots.txt` блокирует обход и **не** указывает на production sitemap. Не блокировать индексирование production.
- Не допускать передачи продового счётчика Метрики из dev: раздельная env конфигурация; prod оставляет прежний `YANDEX_METRIKA_ID`.
- Тесты: app theme + метаданные, noindex non-prod, production no noindex, dev robots, отсутствие инъекции/дубликатов Метрики, ключевые маршруты.

Приёмка: inspect HTML/headers через HTTPS на dev и после выпуска; Search Console и Вебмастер видят только production canonical.

## Исправление nginx robots.txt (2026-11-12)

- После первого dev deploy обнаружено, что в `robots.txt` текст `\\n` передавался буквально, без физических переводов строк. Такая запись не является корректной формой отдельных crawler directives.
- В `docker/nginx/default.conf` заменён двойной escape на одиночный `\\n` (Nginx интерпретирует одиночный escape как перевод строки). Локально подтверждён результат с байтами `0a` между `User-agent: *` и `Disallow: /`.
- В `SeoAppThemeMigrationTest` добавлен регрессионный тест. После выкладки на dev проверить `curl -fsS https://dev.mskba.ru/robots.txt | od -An -t x1`: два байта `0a` обязательны; содержимое — `User-agent: *` на первой строке и `Disallow: /` на второй. Дополнительно проверить `X-Robots-Tag: noindex, nofollow, noarchive` в HTML-ответах.
