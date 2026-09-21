# 004 — Мгновенный review и выдача доступа

## Цель

Закрыть модерацию во время визита без выдачи полевому сотруднику лишних
административных полномочий.

## Работы

- выделить минимальные operational permissions для assisted onboarding/review;
- заменить жёсткую зависимость review от полного admin-доступа там, где это
  необходимо для доверенного агента;
- сохранить backend permission checks и запрет self-approve;
- вариант без review-права: мгновенный запрос admin/superadmin и обновление
  результата на экране агента;
- reviewer всегда фиксируется конкретным пользователем;
- approval продолжает использовать текущий
  `ReviewVenueOwnershipClaimHandler` и создание owner membership;
- успешный результат сразу открывает представителю кабинет площадки;
- запретить shared/общий admin account как штатный operational pattern.
