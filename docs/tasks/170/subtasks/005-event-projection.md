# 005 — Интеграция TrainingSession и Event

## Цель

Разрешить максимум один Event типа training и синхронизировать его как публичную
проекцию занятия.

## Результат

TrainingSession остаётся source of truth; generic Event UI не может независимо
изменить дату, место или lifecycle связанного занятия.

## Статус

Выполнено.
