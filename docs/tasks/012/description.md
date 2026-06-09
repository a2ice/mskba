# 012 - Добавить визуальные tooltip для элементов с атрибутом title

## Оригинальное описание

Пользователь попросил:

> добавить tooltip-ы для всех элементов у которых есть атрибут title - визуально это иконка со знаком вопроса - а в tooltip попадает содержимое атрибута title

## Подробное описание

Нужно добавить единый UI/UX-паттерн для подсказок в теме `mskba_dark`.

Требования:

- находить элементы с атрибутом `title`;
- содержимое `title` показывать в кастомном tooltip;
- визуально обозначать подсказку иконкой со знаком вопроса;
- убрать нативный browser tooltip после инициализации, чтобы не было дубля;
- сохранить доступность через focus/hover;
- не переписывать каждое место вручную, если можно сделать общий enhancer;
- не затрагивать служебные `title`, если они появятся вне пользовательского контента.

## Затронутые файлы

- `resources/themes/mskba_dark/js`;
- `resources/themes/mskba_dark/css`;
- `docs/specification/docker-environment.md` или UI-документация, если понадобится;
- `docs/tasks.md`.

## Проверки

- `npm run build`;
- ручная/визуальная проверка через существующие `title` в confirmation wizard, notifications и modal descriptions.

## Результат

Добавлен общий tooltip enhancer для темы `mskba_dark`.

Поведение:

- JS находит элементы с `title`;
- переносит текст в `data-tooltip-source`;
- удаляет нативный `title`, чтобы не было двойной browser-подсказки;
- вставляет рядом кнопку `?`;
- tooltip показывается на hover и keyboard focus;
- текст подсказки попадает в `aria-label` кнопки.

Поддерживаются два варианта:

- `question` - вариант по умолчанию, добавляет отдельную кнопку `?`;
- `title` - включается через `data-tooltip-variant="title"` и привязывает tooltip к самому элементу без дополнительной иконки.

Для badge-маркеров в confirmation wizard и notification badges выбран вариант `title`, потому что у них уже есть собственный визуальный маркер.

Добавлены файлы:

- `resources/themes/mskba_dark/js/features/tooltips.js`;
- `resources/themes/mskba_dark/css/tooltip.css`.

Обновлены:

- `resources/themes/mskba_dark/js/app.js`;
- `resources/themes/mskba_dark/css/app.css`;
- `docs/specification.md`.

Проверки:

- `npm run build` - пройден.
