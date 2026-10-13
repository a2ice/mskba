# 12. Истории из бизнес-процессов MSKBA

[← Оглавление](README.md) · [Далее →](13-interview-qa.md)

Здесь не перечисление классов, а готовые **технические истории**. Используй схему: бизнес-сложность → инвариант → механизм → trade-off → проверка.

## Кейс 1. Регистрация и подтверждение

**Проблема:** аккаунт, роль, профиль игрока и согласие на обработку ПДн должны создаться согласованно. **Решение:** [RegisterUserHandler](../../app/Modules/Identity/Application/UseCases/RegisterUserHandler.php) координирует создание через DB::transaction. Следующие этапы подтверждения контакта выбрасывают UserContactConfirmed, что позволяет отделить права, поиск дубликатов и уведомления. **Trade-off:** больше переходов между классами, но упрощается добавление каналов и реакций. **Тесты:** [AccountConfirmationWizardTest](../../tests/Feature/Identity/AccountConfirmationWizardTest.php), [UserCanonicalizationTest](../../tests/Feature/Identity/UserCanonicalizationTest.php).

**На интервью:** «Важно было не просто записать пользователя, а не оставить половину регистрационных данных при сбое».

## Кейс 2. Подтверждение контактов несколькими каналами

**Проблема:** email, Telegram, телефон/VK потенциально имеют разные протоколы. **Решение:** Contract + Strategy, конкретно [EmailContactVerificationStrategy](../../app/Modules/Contact/Application/Strategies/EmailContactVerificationStrategy.php). Код хранится как hash, проверяется TTL/cooldown, обработана ошибка доставки. **Trade-off:** интерфейс и resolver требуют дополнительного слоя, зато расширение каналов ожидаемо. **Не утверждай**, что все перечисленные каналы уже отправляют настоящие коды.

## Кейс 3. Модерация площадки

**Проблема:** пользователь добавляет/редактирует площадку, а публикация достоверных сведений требует проверки. **Решение:** выделенные UseCases [SubmitModerationRequestHandler](../../app/Modules/Venue/Application/UseCases/SubmitModerationRequestHandler.php), [ReviewModerationRequestHandler](../../app/Modules/Venue/Application/UseCases/ReviewModerationRequestHandler.php); при approval событие [ModerationRequestApproved](../../app/Modules/Moderation/Domain/Events/ModerationRequestApproved.php) может повлечь подтверждение площадки и начисление первого бонуса. **Trade-off:** moderation state отдельно от основной сущности. **Расширение:** отклонение/отмена и блокирование повторной подачи задают явную семантику ([общий паттерн](../specification/request-moderation-pattern.md)).

## Кейс 4. Владение и персонал площадки

**Проблема:** права зависят не только от системной роли, но и от договора и конкретной площадки. **Решение:** [VenueMembershipAccess](../../app/Modules/Venue/Application/Services/VenueMembershipAccess.php) проверяет active contract и нужное permission через exists(). [ContractMembership](../../app/Modules/Contract/Domain/Models/ContractMembership.php) связывает акторов с resource scope; это контекстная ACL. **Trade-off:** сложнее SQL, зато нет права «редактировать все площадки» за одно локальное назначение.

## Кейс 5. Бронь площадки при одновременных запросах

**Проблема:** слоты — ограниченный ресурс; двойная бронь недопустима. **Решение:** quote и snapshot, receipt с ключом идемпотентности, row locks, проверка конфликтов, [VenueBookingLifecycle](../../app/Modules/VenueBooking/Domain/Services/VenueBookingLifecycle.php) и Outbox. **Trade-off:** больше строк и проверок, зато состояние не зависит от времени обработки HTTP и повторных сообщений. **Тесты:** [VenueRentalCriticalPathE2ETest](../../tests/Feature/VenueBooking/VenueRentalCriticalPathE2ETest.php), [VenueBookingLifecycleTest](../../tests/Feature/VenueBooking/VenueBookingLifecycleTest.php).

~~~text
quote → requested → held → confirmed
                     ↘ rejected/cancelled/expired
Бизнес-правила переходов проверяются сервером, не UI.
~~~

## Кейс 6. Начисление бонусов за подтверждённого приглашённого

**Проблема:** события приходят повторно, маркетинговые условия меняются, дважды начислять нельзя. **Решение:** [RewardEngine](../../app/Modules/Rewards/Application/Services/RewardEngine.php) использует механизм [DirectReferralConfirmedMechanism](../../app/Modules/Rewards/Application/Mechanisms/DirectReferralConfirmedMechanism.php) или [SecondLevelReferralConfirmedMechanism](../../app/Modules/Rewards/Application/Mechanisms/SecondLevelReferralConfirmedMechanism.php), версию reward, RewardGrant с уникальным business fact, а [CreditWalletHandler](../../app/Modules/Finance/Application/UseCases/CreditWalletHandler.php) проводит идемпотентную финансовую операцию. **Trade-off:** версия условий и журнал операций добавляют таблицы, но обеспечивают воспроизводимость начислений.

## Кейс 7. Команда, приглашения и заявка на вступление

**Проблема:** пригласить игрока, рассмотреть заявку, учесть роли по виду спорта и сохранить причины решений — разные сценарии. **Решение:** модели и прикладные сервисы в модуле Team, в том числе [TeamJoinRequestController](../../app/Modules/Team/Presentation/Http/Controllers/TeamJoinRequestController.php), [TeamManagementAccess](../../app/Modules/Team/Application/Services/TeamManagementAccess.php), [TeamRosterService](../../app/Modules/Team/Application/Services/TeamRosterService.php). **Trade-off:** не складывать роли разных видов спорта в одно поле Team; подробнее [TeamSportProfile](../../app/Modules/Team/Domain/Models/TeamSportProfile.php).

## Кейс 8. Уведомления и WebSocket

**Проблема:** изменение бизнес-состояния должно быстро попадать в UI без перезагрузки, но не теряться при offline. **Решение:** запись UserNotification + event + Listener + Reverb private channel, затем HTTP reconcile. **Trade-off:** два канала доставки состояния, зато производительность push и консистентность серверной базы. [Код UI](../../resources/themes/mskba_app/js/notification-counts.js).

## Кейс 9. Подтверждённые данные профиля — WIP

**Проблема:** после подтверждения личности нельзя молча менять имя, фамилию, пол или дату рождения. **Решение (локальная разработка):** [ProfileChangeRequestService](../../app/Modules/Identity/Application/Services/ProfileChangeRequestService.php): snapshot текущего профиля, proposed data, причина, approve/reject/block/cancel, блокировки и защита от устаревшей заявки. В работе отдельный thread с разграничением доступа. **Важно:** не подавать этот пилот как уже задеплоенный результат.

## Кейс 10. Реальные тесты как доказательство

Прежде чем рассказывать о reliability, открой [tests/Feature/VenueBooking](../../tests/Feature/VenueBooking), [tests/Feature/Finance](../../tests/Feature/Finance), [tests/Feature/Rewards](../../tests/Feature/Rewards), [tests/Feature/Identity](../../tests/Feature/Identity). Смотри не только happy path, но также invalid transition, duplicate request, permission denied и race/failure case.

## Как ответить «что самое сложное?»

Выбери **один** из трёх:
1. Конкурентная бронь + idempotency + outbox (backend reliability).
2. Contract ACL и сложные запросы (security + SQL).
3. Гибридный UI + Reverb и offline reconciliation (fullstack).

Если расскажешь 8 технологий подряд, интервьюер запомнит меньше, чем если раскроешь один кейс глубоко.
