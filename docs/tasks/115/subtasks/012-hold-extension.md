# 012 — Переговоры о продлении

## Цель
Позволить запросить и явно согласовать ограниченное продление hold.

## Доменные изменения
Extension request имеет requested_until, reason и `PENDING|APPROVED|REJECTED|CANCELLED`; только APPROVED меняет deadline.

## Миграции
Таблица extension requests, reviewer/decision timestamps, лимиты из policy snapshot.

## Handlers и сервисы
Request/Approve/Reject/Cancel extension; approve под venue mutex повторно проверяет конфликт и лимит.

## Права
Запрашивает инициатор брони, решает коммерческая сторона; собственный запрос нельзя одобрить без соответствующего права.

## UI/API
Причина, старый/новый deadline, история решений; недоступные действия скрыты и защищены сервером.

## События и jobs
ExtensionRequested/Approved/Rejected; после approve переоценивается expiry scheduling.

## Тесты
Одновременный expiry/approve, возникший конфликт, превышение лимита, повтор approve.

## Обратная совместимость
Брони без extension продолжают жить по исходному deadline.

## Критерии приёмки
- запрос сам ничего не продлевает;
- approve атомарен и конфликтобезопасен;
- старые expiry jobs становятся harmless.

