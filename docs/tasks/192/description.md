# 192 — Единая механика попапов

## Источник

Актуальный backlog-item `B044`: привести попапы к общей механике и стилю, закрепить header/footer при прокрутке body, добавить сворачивание и отражать состояние в URL так, чтобы ссылка могла открыть или сразу показать свёрнутый попап.

> В backlog идентификатор `B044` был использован дважды. Более ранний item про FAQ-блоки закрыт в task 180; эта задача относится именно к попапам.

## План

1. Расширить общий `theme::partials.modal.layout`: единые header, прокручиваемый body, необязательный footer и кнопки сворачивания/закрытия.
2. Централизовать lifecycle в `ui-handlers.js`: open, minimize, restore, close, Escape, backdrop, focus trap/restore и блокировка фоновой прокрутки.
3. Ввести URL-контракт `modal=<id>` и `modal_state=minimized`. Обычный deep link открывается через секунду после `load`, свёрнутый — сразу после готовности DOM. Неизвестный id безопасно игнорируется.
4. Сохранить feature-события `modal:opened` / `modal:closed`, добавить `modal:minimized` / `modal:restored` и не переносить в popup-слой доменные правила.
5. Обновить техническую документацию, собрать production assets и выполнить проверки разметки/JS.

## Границы

- Задача унифицирует все попапы, уже построенные на общем Blade layout. Самостоятельные media lightbox и Telegram-specific overlays с другим DOM/API не переписываются без отдельной проверки их media- и platform-specific lifecycle.
- URL меняется через `history.replaceState`, чтобы открытие/сворачивание не засоряло history и не перехватывало кнопку Back.
- URL хранит только UI-состояние, не данные формы, токены или результаты запросов.
- Отдельные backend-блокировки, очереди или конкурентные записи не добавляются; риска deadlock у этой UI-задачи нет.

## Статус

Выполнено прямо в `main` по явному указанию пользователя. Перед стартом `main` обновлён fast-forward до `8f4ade35`, рабочее дерево было чистым.

## Результат

- Общий Blade layout разделён на header, прокручиваемый body и необязательный footer. Header содержит единые Tabler-кнопки сворачивания и закрытия.
- Заголовок shell синхронизируется с активным feature-заголовком без переноса исходного DOM-узла; существующие wizard-селекторы продолжают работать.
- Homepage wizard, вложенный location wizard и действия popup аренды используют неподвижные footer-области. Программное открытие игровых форм и управления правами команды переведено на общий API.
- Lifecycle централизован в `window.MskbaModal`: open/minimize/restore/close, body scroll lock, focus trap/restore, Escape/backdrop и события состояния.
- URL-контракт: `modal=<id>` для открытого состояния и `modal=<id>&modal_state=minimized` для свёрнутого. Изменения используют `replaceState`; close удаляет параметры.
- Размеры и позиция рассчитываются относительно `visualViewport`; добавлены compact/mobile режимы и сохранена Telegram safe-area интеграция.
- Технический контракт описан в `docs/specification/modal-system.md`, краткие входы добавлены в продуктовую и техническую документацию.

### Доработка после production-проверки

- Перед доработкой `main` обновлён fast-forward до `daaff6b1`; 37 параллельных коммитов относятся к task 191 и sticky-header и не меняют общий modal lifecycle.
- Исправлена смена действия кнопки minimize/restore: обработчик читает актуальный DOM-атрибут, а не закэшированное jQuery-значение.
- Сворачивание больше не возвращает focus к инициатору и не запускает ошибочное повторное фокусирование формы; возврат focus сохранён только для закрытия.
- Успешная авторизация очищает из URL временные `modal` и `modal_state`, сохраняя остальные query-параметры и fragment. Правило продублировано на frontend и в общем безопасном backend-resolver, поэтому действует для пароля, регистрации, VK и Telegram.
- Для обоих auth-попапов добавлен компактный dialog шириной до 460 px, чтобы поля не растягивались до общей ширины контентных окон.

## Проверки

- `npm run build` — успешно; tracked `public/build` пересобран согласно текущей стратегии репозитория. Сохраняются известные предупреждения про runtime `/images/home-court.png` и chunks больше 500 kB.
- `php artisan view:cache` — Blade-шаблоны успешно скомпилированы; после проверки compiled views очищены через `php artisan view:clear`.
- `node --check` для общего lifecycle и изменённых feature-файлов — успешно.
- `git diff --check` — успешно.
- После доработки: `php artisan test tests/Feature/Auth tests/Feature/Vk/VkIdAuthenticationTest.php tests/Feature/Telegram/TelegramWebLoginTest.php tests/Feature/Telegram/TelegramBotLoginTest.php` — 48 тестов, 390 assertions, успешно.
- После доработки: `npm run build`, `node --check`, `php artisan view:cache` и `git diff --check` — успешно; production assets пересобраны.
- Полноценная локальная HTTP/визуальная проверка не выполнена: локальный PostgreSQL на `127.0.0.1:5432` не запущен, а Browser runtime не обнаружил подключённых браузеров. Поэтому desktop/mobile визуальная приёмка не заявляется.

## Архитектурная граница

Общий контракт применён ко всем popup на `theme::partials.modal.layout` и динамическому location mini-wizard. Media lightbox, старые venue day overlays, native `<dialog>` редактора цен и Telegram-specific overlays сохраняют отдельный lifecycle: у них специализированные media/platform contracts, и их механическая подмена без отдельной регрессии рискованнее, чем полезна. Эта граница зафиксирована в спецификации и не маскируется как общий DOM-контракт.
