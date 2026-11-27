# Пакет закрытых DEV-черновиков #1037

Исходники: 68a44e453d03ec5d43e50b9ed76937dcf41a424b, #1142. В JSON только поля проверенного EditorialNewsAgentApiController::store. Заголовок CMS задаётся полем title; исходный H1, редакционные указания и метаданные из тела исключены. Summary взято из утверждённого чернового Description; meta_title/meta_description сохранены. Файлы намеренно не находятся в ops/editorial-news/requests — не запускают production bridge.

| Alias / JSON | Исходник | Обложка (внешний архив из чата) |
|---|---|---|
| kak-vybrat-basketbolnyy-zal-dlya-arendy | rental-guide-moscow.md | kak-vybrat-basketbolnyy-zal-dlya-arendy.webp |
| stoimost-arendy-basketbolnogo-zala | rental-cost-winter.md | stoimost-arendy-basketbolnogo-zala.webp |
| gde-igrat-v-basketbol-zimoy-v-moskve | winter-basketball-moscow.md | gde-igrat-v-basketbol-zimoy-v-moskve.webp |
| kak-organizovat-basketbolnuyu-igru-s-druzyami | organize-basketball-game.md | kak-organizovat-basketbolnuyu-igru-s-druzyami.webp |

Утверждённая серия «Гид MSKBA», исправленная эмблема, WebP 1600×840. Четыре бинарных файла подготовлены отдельным архивом, в этот PR не включены. Изображения иллюстративные, не фотографии конкретных залов.

## Приёмка

1. Только dev.mskba.ru. В Filament проверить существующие записи, в том числе черновики, уникальность alias и совпадение тем. Публичный 404 не доказывает уникальность.
2. Использовать штатный редактор или ограниченный DEV editorial-news:write. API принимает multipart: поля из JSON и соответствующий файл cover. Не передавать JSON-файл целиком как поле content. Не использовать production токен, workflow или publish endpoint. Не передавать токены в чат, git или лог.
3. Проверить четыре результата: status=draft, отсутствие публичной публикации, обложка, формат Markdown, единственный H1, SEO поля и корректные ссылки. При конфликте alias или неопределённом результате запроса не повторять POST автоматически, сначала сверить CMS.
4. Production примеры цен датированы 27.11.2026, требуют перепроверки перед выпуском и ссылок на реальные production карточки. DEV ID отличаются; не подменять ссылки. Не публиковать как обзор рынка.
5. Ссылки на будущие материалы добавлять после проверки доступности страниц; индексация DEV запрещена. MAIN, production, TG/VK не трогать.

27.11: публичные DEV-проверки двух кандидатов вернули 404, остальные проверки завершились timeout. Сохранённые CMS aliases, доступ к API и фактический deploy не подтверждены. Черновики в CMS пока не созданы.
