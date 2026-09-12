# 173 — Перевести каталог турниров на `default_category`

## Цель

Привести `/tournaments` к общему стандарту `default_category` после Task 171, сохранив tournament lifecycle, режимы набора, формат и существующие права.

## Требования

- общий shell `sidebar + content`, sticky toolbar, desktop sidebar filters и mobile filter popup;
- режимы `cards|list|map`, default `cards`;
- сохранить/нормализовать существующие tournament filters и поиск;
- в карточках и строках показывать только полезные для каталога данные: статус, даты, формат, режим формирования/набора, площадку и ключевую CTA;
- map view использовать только для турниров, у которых есть достоверная связанная/default venue с координатами;
- отсутствие координат не должно скрывать турнир из `cards/list`, а в `map` должно быть понятно, что часть турниров не имеет точки;
- sidebar navigation задаётся контекстом. Кандидаты для согласования: активные/открытые, ближайшие, прошедшие, мои турниры, создать турнир;
- query state (`view`, search, filters, pagination) не теряется.

## Критерии приёмки

- каталог визуально и адаптивно следует Task 171;
- tournament lifecycle/permissions не изменены presentation-рефакторингом;
- фильтры и все публично доступные турниры сохраняются;
- `cards|list|map` работают предсказуемо;
- профильные Tournament tests, `npm run build`, `git diff --check`;
- ручной production smoke после merge.

## Зависимости

Task 171.

## Ветка

`feature/173`

## Статус

Запланировано.
