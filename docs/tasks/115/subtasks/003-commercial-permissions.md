# 003 — Коммерческие роли и права

## Цель
Отделить управление карточкой площадки от управления арендой, платежами и договорённостями.

## Статус
Выполнена и зафиксирована в `feature/115`.

## Доменные изменения
`ContractMembership`: `OWNER`, `MANAGER`, `BOOKING_OPERATOR`, `FINANCE_VIEWER`; права вычисляются серверной policy.

## Миграции
Таблица memberships с unique `(venue_id,user_id,role)` и индексами по venue/user; soft revoke через статус или `revoked_at`.

## Handlers и сервисы
Grant/Revoke/Change membership с единым permission resolver; запрет удалить последнего OWNER без передачи владения.

## Права
OWNER и пользователь с `manage_memberships` назначают права; superadmin имеет аварийный доступ с аудитом.

## UI/API
Компактная таблица участников договора, модальное редактирование прав, явное предупреждение при отзыве.

## События и jobs
MembershipGranted/Revoked после commit; уведомление затронутому пользователю.

## Тесты
Матрица ролей, IDOR, отозванное право в активной сессии, последний OWNER, superadmin override.

## Обратная совместимость
Старые права карточки площадки продолжают работать, но не дают коммерческих команд.

## Критерии приёмки
- любая коммерческая команда защищена policy/use-case;
- UI не является единственной защитой;
- изменение прав полностью аудируется.

## Результат выполнения

- существующая `ContractMembership` расширена ролями `BOOKING_OPERATOR` и
  `FINANCE_VIEWER`; `OWNER` и `MANAGER` получили явную коммерческую матрицу;
- card permissions и commercial permissions разделены, creator/bootstrap
  fallback никогда не проходит `VenueCommercialAccess`;
- Grant/Change/Revoke повторно авторизуются в handler, блокируют venue как mutex,
  валидируют permission snapshot и используют soft revoke существующего Contract;
- последний OWNER не может быть отозван или понижен без отдельной передачи;
  выдача OWNER остаётся только в ownership-claim flow;
- UI использует общий predictive search, компактный список участников,
  редактирование фактических прав и явное подтверждение отзыва;
- события grant/revoke отправляются after commit и создают уведомление; выключенный
  `rental_flow` блокирует HTTP и application handler;
- superadmin override разрешён, повторно проверяется сервером и помечается в
  комментарии аудируемого Contract.

Отдельная таблица memberships не добавлялась: она дублировала бы уже каноническую
Contract ACL. Историческая уникальность обеспечивается venue-lock и проверкой
активной роли; после soft revoke допустим новый Contract той же роли.
