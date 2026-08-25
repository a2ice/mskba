# 013 — Внешняя оплата и платёжное окно

## Цель
Поддержать оплату вне платформы, сохранив слот на ограниченное время и фиксируя решение коммерческой стороны.

## Доменные изменения
Payment state: `NOT_REQUIRED|NOT_STARTED`, `READY`, `WINDOW_OPEN`, `CLAIMED`, `CONFIRMED`, `REJECTED|DISPUTED`, `EXPIRED`. Booking остаётся HELD до payment CONFIRMED.

## Миграции
Payment attempts/claims, window deadline, method, evidence metadata, reviewer; суммы из immutable quote.

## Handlers и сервисы
OpenWindow, ClaimPaid, Confirm/RejectPayment; effective protection deadline учитывает открытое окно.

## Права
Заявитель заявляет оплату; finance/owner подтверждает. Никто не может подтвердить клиентской суммой.

## UI/API
Реквизиты, таймер, загрузка допустимого доказательства, статусы проверки и спор без хранения лишних платёжных данных.

## События и jobs
PaymentWindowOpened/Claimed/Confirmed/Rejected/Expired; expiry job условный и идемпотентный.

## Тесты
Callback/confirm около deadline, двойной claim, неверная сумма, истечение, параллельный cancel.

## Обратная совместимость
`NOT_REQUIRED` сохраняет бесплатный/ручной flow; внутренний кошелёк и возвраты не входят в этап.

## Критерии приёмки
- CLAIMED не подтверждает booking;
- только CONFIRMED переводит booking дальше;
- платёжное окно не создаёт бесконечный hold.

