# 08. ACL, RBAC и приватность

[← Оглавление](README.md) · [Далее →](09-frontend.md)

## Почему одной роли мало

**RBAC**: роль определяет типовые права («админ», «модератор», «пользователь»). **ACL/permission checking**: конкретное действие разрешено или запрещено для конкретного субъекта в контексте ресурса. MSKBA сочетает оба подхода.

~~~text
Можно ли пользователю U изменить площадку V?
       │
       ├─ аккаунт действующий / не заблокирован?
       ├─ глобальные роли и operational permission?
       ├─ членство в договорном контексте площадки V?
       ├─ нужное permission + период действия контракта?
       └─ ограничения и правила текущего действия?
                ↓
             Allow / Deny
~~~

## Слой глобальных ролей

[UserSystemRoleEnum](../../app/Modules/Identity/Domain/Enums/UserSystemRoleEnum.php) содержит USER, MODERATOR, EDITOR, ADMIN, SUPERADMIN, SYSTEM, label(), numericValue() и atLeast(). Это **системная роль**, не «игрок/тренер»: спортивные роли отдельно живут в [UserParticipationRoleEnum](../../app/Modules/Identity/Domain/Enums/UserParticipationRoleEnum.php).

## Точечные операционные permissions

[UserOperationalPermissionChecker](../../app/Modules/Identity/Application/Services/UserOperationalPermissionChecker.php) проверяет canonical пользователя, блокировку/удаление, явный override is_allowed и defaultAllowedFor(system_role). Для чувствительного review_profile_changes дополнительно требует подтверждённый аккаунт и роль минимум ADMIN (в локальном WIP).

**Зачем:** позволять делегировать отдельные действия, не выдавая все полномочия системной роли.

## Контекстные права площадки

[VenueMembershipAccess](../../app/Modules/Venue/Application/Services/VenueMembershipAccess.php) проверяет договорные членства в конкретном Venue: contract family/status, valid-from/to, набор permissions. Метод allows(User, Venue, VenuePermissionEnum) завершается exists(), без загрузки всех membership как PHP-коллекции.

**Бизнес-пример:** сотрудник может управлять расписанием одной площадки, но не соседней; владелец имеет иной уровень полномочий.

**Очень важно:** скрыть кнопку в браузере ≠ запретить действие. Сервер обязан заново проверять доступ на каждом mutating endpoint.

## ACL vs ACL (Anti-Corruption Layer)

В интервью **ACL** может значить:
1. **Access Control List** — права на ресурс (актуально для этого раздела).
2. **Anti-Corruption Layer** из DDD — адаптер на границе чужой модели, чтобы она не загрязняла домен.

В дереве проекта есть директории Infrastructure/ACL с .gitkeep. **Не выдавай их наличие за полноценную реализацию Anti-Corruption Layer**. Уточни у интервьюера, что имеется в виду.

## Приватность и подтверждение

[UserPrivacyAccessService](../../app/Modules/Identity/Application/Services/UserPrivacyAccessService.php) отделяет, что зритель вправе видеть, от того, что существует в БД. Подтверждение аккаунта — отдельный бизнес-факт; контакт и пользовательские данные могут влиять на разрешённые действия. Публичный профиль не должен раскрывать частные данные только потому, что пользователь авторизован.

## WIP-пилот: треды заявок на изменение профиля

В локальном коде от 13.10.2026 — [ProfileRequestThreadAccess](../../app/Modules/Notification/Application/Services/ProfileRequestThreadAccess.php), [RequestThreadMembershipManager](../../app/Modules/Notification/Application/Services/RequestThreadMembershipManager.php). Концепция: выдача права на рассмотрение **не раскрывает автоматически историю старых заявок**; участие закрепляется отдельным членством; отзыв права блокирует просмотр содержимого. Важно: этот код **не закоммичен, не подтверждён на production**.

Узкий технический пример — [ProfileChangeRequestNotificationService](../../app/Modules/Identity/Application/Services/ProfileChangeRequestNotificationService.php) с whereExists для permission cursor; при масштабировании стоит измерить сложность SQL и индексы.

## Вопросы

**«Что будет, если UI спрятал кнопку, а пользователь напрямую отправил POST?»** Серверный guard, обработчик/Policy обязаны отклонить его независимо от UI.

**«Почему не сделать admin=true?»** Это слишком крупный доступ: нельзя делегировать отдельные права, учитывать срок действия договора или ресурс.

**«Есть ли у вас zero trust?»** Не используй эту формулировку без конкретных доказательств; корректно сказать «проверяем разрешение на сервере в момент действия».

**«Как оптимизировать ACL-запрос?»** Сначала установить, проверяем ли факт существования (EXISTS) или строим список доступных ID; не плодить JOIN с many-to-many и DISTINCT без замера; проверять индекс по user_id, scope_type, scope_id и статусам.
