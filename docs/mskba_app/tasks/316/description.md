# MSKBA App / 316 — SEO metadata (no visual layout changes)

Связанная задача: [docs/tasks/316](../../../tasks/316/description.md).

Изменения касаются только `resources/themes/mskba_app/views/layouts/app.blade.php` и общего head partial: `html lang=ru`, `title`, `meta description/robots/keywords`, `canonical`, JSON-LD, Open Graph, Twitter Card, Яндекс-верификация и подключение Метрики только на основном хосте.

**Визуальные элементы UI Kit не изменять.** Запрещены изменения DOM основного контента, заголовка секций или пользовательских стилей в рамках этого шага.

Проверки: валидный HTML head, единственный Metrika init на `mskba.ru`, noindex для dev, правильные fallback title/description и отсутствие production analytics в dev.

Приёмка: автоматические feature tests и просмотр исходного HTML на dev. Перенос старого H1/новостей/FAQ — отдельно в 316/004.
