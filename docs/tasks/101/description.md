# 101 - Канонизация и поиск дублей пользователей

## Оригинальное описание

Добавить базовый механизм поиска и разрешения дублей пользователей без физического переноса исторических связей.

## Подробное описание

Нужно реализовать аддитивный слой user canonicalization по аналогии с canonical venue:

- nullable self-FK `users.canonical_user_id`;
- отдельные кандидаты `user_duplicates` со статусами `pending`, `rejected`, `merged`;
- структурированные evidence с hash нормализованного значения и отслеживанием текущего набора доказательств;
- повторно отклонённая пара не переоткрывается при неизменном evidence, но переоткрывается при любом новом актуальном evidence;
- detector не выполняет merge автоматически;
- решение о canonical user принимается явно пользователем в доказанном self-service сценарии или суперадминистратором;
- после merge alias-пользователь не удаляется, его старые связи сохраняют исходный `user_id`;
- для текущей работы приложения alias должен разрешаться в canonical пользователя;
- новые действия после авторизации должны выполняться от canonical пользователя;
- profile/player profile/roles и другие текущие данные на первом этапе читаются у canonical пользователя; reconciliation данных alias оставляется отдельной будущей задачей;
- существующий Telegram login UI не расширяется дополнительными призывами ко входу через Telegram.

### Evidence первой версии

- подтверждённый Telegram identity;
- подтверждённый email;
- подтверждённый телефон;
- точное совпадение профиля по нормализованным ФИО и дате рождения;
- ручное evidence администратора.

Scan-managed evidence (`verified_contact`, `exact_profile_identity`) деактивируется, если исходное совпадение больше не существует. Историческая запись evidence сохраняется, но `is_active=false` и больше не может служить основанием для merge.

## Точки запуска detection

Detection только создаёт/обновляет кандидата и никогда сам не меняет `canonical_user_id`.

- завершение обычной регистрации;
- подтверждение контакта пользователя (`UserContactConfirmed`);
- сохранение профиля (для точного совпадения ФИО + дата рождения);
- подтверждённые Telegram-сценарии, синхронизирующие verified Telegram contact;
- ручная привязка Telegram в аккаунте; при конфликте создаётся `telegram_identity` evidence;
- ежедневная команда `identity:scan-user-duplicates` в 03:15;
- ручной запуск той же Artisan-команды.

## Точки resolution

`canonical_user_id` может измениться только после явного resolution:

1. **Self-service** — только пользователь, входящий в пару, после свежей подписанной Telegram-аутентификации. Результат Telegram-аутентификации превращается в одноразовый server-side proof, привязанный к candidate, canonical actor и текущей session. Proof живёт ограниченное время (по умолчанию 10 минут) и потребляется при merge. Сохранённого historical evidence недостаточно.
2. **Admin resolution** — только подтверждённый `SUPERADMIN`. HTTP route защищён Gate, а сам application use-case независимо повторно проверяет canonical actor, его статус и системную роль, поэтому будущий внутренний caller не сможет обойти эту границу доверия. Перед merge требуется явное подтверждение того, что способы входа alias после canonicalization получат доступ к canonical account. Для пар с расширенными ролями требуется дополнительное подтверждение.

Self-service разрешён только между обычными `USER` accounts. `SUPERADMIN` и `SYSTEM` вообще нельзя объединять через механизм дублей. Пары с `EDITOR`/`MODERATOR`/`ADMIN` доступны только для ручного superadmin resolution.

## Security invariants

Canonical merge является security-sensitive операцией: после merge credentials и внешние identities alias фактически становятся способами аутентификации canonical пользователя. Поэтому:

- совпадение ФИО/email/phone/Telegram само по себе никогда не выдаёт доступ к другому аккаунту;
- `rejected` и `merged` candidate нельзя повторно объединить через обычный resolution;
- нельзя merge candidate без хотя бы одного актуального evidence;
- self-service требует свежий одноразовый proof, а не только сохранённое evidence;
- non-self-service merge/reject требует подтверждённого `SUPERADMIN` не только на HTTP-слое, но и внутри application use-case;
- blocked source или blocked canonical account не должен аутентифицироваться ни через обычный login, ни через Telegram;
- уже существующая web-сессия любой alias identity на следующем запросе разрешается в canonical; если canonical account заблокирован, такая сессия немедленно инвалидируется;
- сразу после корректного merge старые пароли alias могут использоваться как credentials той же canonical identity, чтобы пользователь не потерял доступ;
- после первой последующей смены пароля пользователем или установки временного пароля superadmin пароль становится единым credential canonical identity: новый пароль хранится только у canonical account, пароли aliases очищаются, а `remember_token` ротируется у всей identity group; старый alias-пароль после такой ротации больше не работает;
- старые login identifiers alias (например username или verified contact) могут продолжать находить ту же canonical identity, но после ротации принимают только актуальный пароль canonical account;
- Telegram callbacks, создающие новые действия (участие в мероприятии, голосование), выполняются от canonical user;
- Telegram notification delivery ищет verified доступный private chat по всей identity group, но отправляет сообщение только в один детерминированно выбранный аккаунт;
- права и системные роли берутся только у canonical пользователя;
- merge/reject `UserDuplicate` входят в общий audit trail: сохраняются изменения candidate вместе с actor/context аудита;
- неуспешный admin merge возвращает понятную validation/domain-ошибку в повторно открытое модальное окно и фиксирует безопасное событие `merge_failed` в Audit и runtime log; значения формы и raw IP в запись не попадают;
- `identityIds()` нельзя механически применять ко всем `user_id` запросам: identity-wide lookup допускается только там, где нужно читать физически сохранённую внешнюю identity/историю alias.

## Безопасность и обратимость данных

Первый этап не переносит внешние ключи старых сущностей и не удаляет alias accounts. Canonicalization фиксируется только после явного resolution. Исторические записи остаются обратимыми и трассируемыми.

После первого реального merge стандартный rollback миграции намеренно запрещён: удаление `canonical_user_id` без отдельной процедуры восстановления разрушило бы identity graph.

## Конкурентность и integration hardening

- merge, смена пароля и изменение статуса блокируют всю identity group в одинаковом порядке `users.id`, чтобы не создавать инверсию блокировок и вероятный deadlock;
- после получения блокировок use-case повторно проверяет identity roots и отменяет операцию, если параллельный merge уже изменил graph;
- candidate pair создаётся через атомарный `insertOrIgnore`, поэтому два одновременных detector run не падают на unique constraint;
- evidence values хешируются keyed HMAC с application key, что не позволяет подбирать телефоны и email по готовой rainbow table;
- identity-aware reads добавлены в текущие сценарии турниров, мероприятий, команд и площадок. Исторические physical `user_id` при этом не переписываются.
- контакты canonical identity читаются и управляются по всей группе canonical + aliases: подтверждённый email или телефон alias остаётся на исходной записи для аудита, но отображается в кабинете canonical пользователя, участвует в проверке уникальности и может быть выбран основным;
- повторное добавление контакта, уже принадлежащего alias той же identity, не считается конфликтом с другим пользователем и не создаёт дубль.
