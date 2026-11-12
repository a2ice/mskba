# 316/001 — Inventory / SEO baseline

Статус: **в работе**.

1. Собрать полное множество URL: production `sitemap.xml` + Яндекс Вебмастер «страницы в поиске», Google Search Console Pages/Links + исторические URL из access logs (не только текущий sitemap) + внутренние ссылки.
2. Снять `GET/HEAD` status, redirect chain, canonical, robots, lang, Title, Description, Keywords, H1, OG/schema, основной текст, изображения/alt, published dates, internal links; не скачивать персональные данные.
3. Точно определить пары `old_path => target_path`, provenance, приоритет трафика, ручное решение для неразрешённых случаев.
4. Сформировать immutable baseline артефакт CSV/JSON (дата, источник, ревизия); сравнить с dev, затем со стендом из prod-копии.
5. Отметить и проверить отдельно: `/news` → `/feed`, FAQ, legal, статьи, секции, пагинацию, дубли, пользовательские профили, архивные URL.
6. Репорты CLI и ручная приёмка. Не считать отсутствие URL в sitemap доказательством 404.

Исходная проверка 12.11.2026: 51 production / 20 dev / 13 совпадающих / 38 отсутствующих / 7 dev-only путей (текущие XML данные, **не полный индекс**).

Приёмка: воспроизводимый реестр, критические URL сгруппированы, назначено действие, нет скрытых 404 и placeholder-200.
