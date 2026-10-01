# 243 - SEO-аудит, мультиканальная публикация и автоматизация новостей

## Оригинальное описание

Проверить внутреннее SEO MSKBA, затем расширить публикацию новостей с Telegram на VK и Instagram и построить редактируемый автоматизированный конвейер источников: сбор материалов, собственная редакционная переработка, генерация изображения и публикация на сайте и в социальных сетях.

## Подробное описание

Задача делится на независимые этапы, чтобы каждый можно было проверить и выкатить отдельно.

1. Технический SEO foundation: единые meta/title/canonical/robots/Open Graph/Twitter Card, корректный язык документа, structured data, sitemap, robots.txt и смысловые alt для контентных изображений.
2. Универсальный publication layer поверх существующей Telegram-публикации; VK становится первым новым adapter.
3. Instagram publishing adapter для professional account через официальный Instagram Platform API.
4. Реестр источников в админке: тип, URL/provider id, активность, расписание, trust level и правила импорта.
5. Fetch/normalize/deduplicate pipeline для сайтов, VK и других разрешённых источников.
6. AI editorial draft: выделение фактов, собственный заголовок/тизер/текст, сохранение provenance и ссылки на первоисточник.
7. Генерация обложки через уже существующий GitHub-hosted OpenAI image workflow.
8. Редакторская очередь, scheduling, ручной запуск, retry/error status и наблюдаемость.
9. Измерение результата через Search Console/Метрику и корректировка структуры/тем.

### Архитектурные ограничения

- `ContentItem` остаётся канонической публикацией сайта.
- Telegram/VK/Instagram не должны становиться источниками истины для текста материала.
- Новые социальные каналы реализуются adapter-слоем, а не растущими `if` внутри существующего Telegram manager.
- Внешний материал хранит provenance: source id, source URL, external id и fingerprint.
- Дедупликация выполняется до генерации текста и картинки.
- «Переформулировать своими словами» не используется как способ копировать полный чужой материал: конвейер извлекает факты, делает собственную редакционную подачу, сохраняет ссылку на источник и поддерживает правила цитирования/атрибуции.
- Автопубликация без человека включается только отдельно для доверенных типов источников после проверки качества.

### Текущее состояние аудита

На production подтверждены: canonical для `/feed` работает, Open Graph title/url/type есть, но отсутствует `/sitemap.xml`, meta description на главной и ленте, document language определяется как `en`, а у части контентных изображений пустой alt. В коде уже существуют `meta_title`, `meta_description`, `meta_keywords`, `PageSeoResolver`, Open Graph и Telegram publication/job-контур.

Google Search Console через доступный connector на момент старта задачи недоступен из-за завершившейся подписки GSC Wizard; показатели и URL Inspection нужно повторить после восстановления доступа.

## Этапы

- [x] 001 - SEO foundation (PR #282, CI green; ожидает merge)
- [ ] 002 - Public page SEO templates and structured data
- [ ] 003 - Social publication abstraction + VK
- [ ] 004 - Instagram publication
- [ ] 005 - Content sources admin
- [ ] 006 - Ingestion and deduplication
- [ ] 007 - AI editorial draft and image
- [ ] 008 - Editorial queue and scheduler
- [ ] 009 - SEO/traffic measurement loop
