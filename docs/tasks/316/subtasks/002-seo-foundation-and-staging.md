# 316/002 — SEO layout и non-production indexing

Статус: **реализация в feature branch, требует CI + dev acceptance**.

- Восстановить в `mskba_app`: config-based title/description fallback, canonical, keywords, OG/Twitter, JSON-LD, `html lang=ru`, Яндекс-верификацию, Яндекс.Метрику и noscript, meta-robots.
- Сохранять явно указанные SEO значения выше fallback. Не менять действующую `mskba_dark` без необходимости.
- `X-Robots-Tag: noindex, nofollow` для неканонических хостов на уровне Laravel; dev `robots.txt` блокирует обход и **не** указывает на production sitemap. Не блокировать индексирование production.
- Не допускать передачи продового счётчика Метрики из dev: раздельная env конфигурация; prod оставляет прежний `YANDEX_METRIKA_ID`.
- Тесты: app theme + метаданные, noindex non-prod, production no noindex, dev robots, отсутствие инъекции/дубликатов Метрики, ключевые маршруты.

Приёмка: inspect HTML/headers через HTTPS на dev и после выпуска; Search Console и Вебмастер видят только production canonical.
