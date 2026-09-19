# Task 207 — Eligibility каталога участников

## Проблема

После Task 205/206 `/participants` показывал только пользователей, у которых была хотя бы одна активная participation role. Дополнительно `catalogEntry()` отбрасывал пользователей, если их role-page была закрыта настройкой `ROLE_*`.

На production это приводило к двум заметным симптомам:

- подтверждённые аккаунты без роли вообще отсутствовали в «Все участники»;
- подтверждённые пользователи с активной ролью player/coach не попадали в соответствующий каталог, если детальная role-page была закрыта (что является default для ROLE_*).

## Исправление

- `/participants` больше не требует `whereHas(participationRoles)`.
- role preset/filter добавляет `whereHas` только для выбранной активной роли.
- `DISCOVERABILITY` отвечает за присутствие в каталоге.
- `ROLE_*` отвечает за доступность детальной role-page, а не за сам факт membership в role catalog.
- confirmed user без роли отображается в «Все участники» с нейтральным badge «Участник».
- если PROFILE/distribution consent закрывают персональные данные, карточка не раскрывает имя/аватар: используется nickname/username и состояние «Профиль закрыт».
- если role-page закрыта, но общий PROFILE открыт, кнопка ведёт на общий профиль, а не на 404 role URL.

## Проверки

- confirmed roleless user есть в `/participants`, но отсутствует в `/players`;
- active player остаётся в `/players` при `ROLE_PLAYER=nobody`;
- `DISCOVERABILITY=nobody` по-прежнему полностью исключает пользователя;
- enforced distribution consent без разрешения не раскрывает имя профиля и avatar.
