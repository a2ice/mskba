# 175 — Нормализовать форматы спортивных секций

## Контекст

Task 170 ввёл секции с `training_mode = individual|small_group|team` и переиспользовал игровой `GameFormatEnum` (`basketball_5x5|streetball_3x3|streetball_1x1`). Для долгоживущей тренировочной секции эта детализация оказалась не той семантикой, которая нужна продукту.

## Цель

Упростить и отделить характеристики секции от формата конкретной игры.

### Формат тренировок

Оставить два значения:

- `individual` — Индивидуально;
- `group` — Групповой.

Существующие `small_group` и `team` мигрировать в `group`. Не оставлять legacy-значения в формах, фильтрах или новых данных.

### Игровое направление секции

Секция должна хранить укрупнённое направление:

- `basketball` — Баскетбол;
- `streetball` — Стритбол;
- `other` — Другое.

Не использовать для этого `Event\GameFormatEnum`: он описывает формат конкретной игры и размер сторон, поэтому связь SportsSection → Event module создаёт неверную доменную семантику.

Ввести enum внутри SportsSection (рабочее имя `SportsSectionGameFormatEnum`/`SportsSectionFormatEnum`). Миграция текущих значений:

- `basketball_5x5` → `basketball`;
- `streetball_3x3`, `streetball_1x1` → `streetball`;
- `custom` → `other`.

## Объём

Обновить:

- schema/data migration;
- model casts/fillable и handlers;
- create/edit forms;
- public/account filters;
- factories/seeders;
- Task 170/specification, чтобы документация больше не описывала старую модель;
- профильные tests.

Не менять форматы уже созданных `Game`: задача относится только к SportsSection.

## Критерии приёмки

- новые секции принимают только два training mode и три section format;
- существующие данные мигрированы детерминированно;
- нигде в SportsSection UI/API не предлагаются `Малая группа`, `Команда`, `5×5`, `3×3`, `1×1` как значения этих двух полей;
- Event/Game format не регрессирует;
- профильные tests и `git diff --check`.

## Зависимости

Task 170.

## Ветка

`feature/175`

## Статус

Запланировано.
