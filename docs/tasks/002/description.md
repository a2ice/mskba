# 002 - Рефакторинг тем: переименовать основную тему в mskba_dark, минимальную тему в blank

## Оригинальное описание

Рефакторинг тем: текущая проработанная тема `mskba_light` должна стать `mskba_dark`, а текущая минимальная `mskba_dark` должна стать `blank`.

## Подробное описание

Нужно устранить путаницу в именовании тем.

Текущее состояние:

- `resources/themes/mskba_light` фактически является основной проработанной темой проекта;
- `resources/themes/mskba_dark` является минимальной темой-заготовкой;
- `config/themes.php` сейчас активирует `mskba_dark` по умолчанию, что не соответствует фактическому расположению основного UI.

Целевое состояние:

- текущую `resources/themes/mskba_light` переименовать в `resources/themes/mskba_dark`;
- текущую `resources/themes/mskba_dark` переименовать в `resources/themes/blank`;
- обновить `config/themes.php`, чтобы активной темой по умолчанию был новый `mskba_dark`;
- оставить `blank` как минимальную/заготовочную тему;
- проверить `ThemeResolver::viteInputs()`, Blade namespace `theme`, Vite entrypoints и fallback `pages/system/view_not_found`;
- убедиться, что страницы auth/account/venues работают через новую основную тему `mskba_dark`;
- обновить документацию после рефакторинга.

## Затронутые области

- `resources/themes`;
- `config/themes.php`;
- `vite.config.js`, если там есть прямые ссылки на темы;
- Blade-шаблоны, если есть прямые ссылки на `mskba_light` или старый `mskba_dark`;
- документация `docs/project.md` и `docs/specification.md`;
- проверки frontend-сборки.

## Проверки

- `rg "mskba_light|mskba_dark"`;
- `npm run build` или `make build`;
- `php artisan route:list`;
- ручная проверка ключевых страниц: `/`, `/login`, `/register`, `/venues`, `/account`.

## Выполнение

Ветка: `refactor/002`.

Выполненные изменения:

- директории тем переименованы через временную директорию, чтобы не потерять старую `mskba_dark`;
- текущая проработанная `resources/themes/mskba_light` стала `resources/themes/mskba_dark`;
- текущая минимальная `resources/themes/mskba_dark` стала `resources/themes/blank`;
- `config/themes.php` обновлен: активная тема по умолчанию `mskba_dark`, доступные темы `mskba_dark` и `blank`;
- `vite.config.js` обновлен: Vite entrypoints теперь указывают на `mskba_dark` и `blank`;
- `docs/project.md` и `docs/specification.md` обновлены под новое фактическое состояние тем;
- frontend assets пересобраны через `npm run build`; `public/build` игнорируется `.gitignore`, поэтому build-артефакты не входят в коммит.

## Результат

- `mskba_dark` теперь является основной темой проекта.
- `blank` теперь является минимальной темой-заготовкой.
- В основной теме есть страницы auth/account/venues и fallback `pages/system/view_not_found`.
- Страница `dashboard` по-прежнему отсутствует и должна решаться отдельной задачей, если экран нужен.

## Выполненные проверки

- `rg "mskba_light|mskba_dark"` - выполнено для контроля ссылок;
- `npm run build` - пройден;
- `php artisan route:list` - пройден, показал 28 маршрутов;
- `php artisan optimize:clear` - пройден;
- `php artisan tinker` view-check - пройден: ключевые views auth/account/venues и fallback `pages.system.view_not_found` существуют в активной теме;
- HTTP-проверка через локальный `php artisan serve` - пройдена: `/`, `/login`, `/register`, `/venues` вернули `200`, `/account` вернул `302` на `/login` для гостя.

## Статус

Реализация и проверки выполнены.
