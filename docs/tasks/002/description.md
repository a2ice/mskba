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

## Статус

Задача создана, к выполнению не приступали.
