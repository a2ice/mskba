# 03. Современный PHP на примерах MSKBA

[← Оглавление](README.md) · [Далее →](04-events-and-integration.md)

## Что реально видно в репозитории

| Возможность | Зачем | Где посмотреть |
| --- | --- | --- |
| PHP 8.x backed enum | Не передавать случайные строки статусов и ролей | [UserSystemRoleEnum](../../app/Modules/Identity/Domain/Enums/UserSystemRoleEnum.php) |
| match | Исчерпывающие, выражающие значение ветвления | [ContactValue](../../app/Modules/Contact/Domain/ValueObjects/ContactValue.php) |
| readonly class / properties | После создания ссылка/значение не может быть переназначено | [UsernameVO](../../app/Modules/Identity/Domain/ValueObjects/UsernameVO.php) |
| constructor property promotion | Явные и короткие зависимости конструктора | [RegisterUserHandler](../../app/Modules/Identity/Application/UseCases/RegisterUserHandler.php) |
| union/nullable/typed returns | Контракт входа/выхода вместо неявных типов | [ContactVerificationStrategy](../../app/Modules/Contact/Application/Contracts/ContactVerificationStrategy.php) |
| PHP attributes #[...] | Декларативные метаданные, читаемые фреймворком | [User](../../app/Modules/Identity/Domain/Models/User.php) с #[Fillable] и #[Hidden] |
| interfaces | Контракт заменяемой реализации | [RewardMechanism](../../app/Modules/Rewards/Application/Contracts/RewardMechanism.php) |
| Exceptions | Предметные ошибки отличимы от технических | [VenueBookingTransitionException](../../app/Modules/VenueBooking/Domain/Exceptions/VenueBookingTransitionException.php) |

## Enum vs константы vs таблица

**Enum** — фиксированный набор типов в коде: роль пользователя, статус, тип контакта. Backed enum имеет конкретное строковое значение для хранения. В [UserSystemRoleEnum](../../app/Modules/Identity/Domain/Enums/UserSystemRoleEnum.php) есть label(), numericValue() и atLeast(); UI-подписи и сравнение ролей не размазаны по контроллерам.

**Константы** уместны для простых настроек и не всегда нуждаются в собственной структуре. **Таблица БД** лучше для редактируемого администратором каталога: например вознаграждение хранится как Reward/RewardVersion, а его вычисляющее поведение — стратегия.

~~~php
// Иллюстрация фактического паттерна:
if ($user->system_role->atLeast(UserSystemRoleEnum::ADMIN)) {
    // Можно дополнительно проверить предметное permission.
}
~~~

**Сложный вопрос:** почему не хранить label в DB? Потому что для системного enum набор контролирует версия приложения; локализацию можно переопределять UI-слоем. База нужна, если продукт требует управляемого каталога.

## Value Object

[UsernameVO](../../app/Modules/Identity/Domain/ValueObjects/UsernameVO.php) — final readonly, конструируется через fromString(), триммит, проверяет длину 3–32, формат и переводит в нижний регистр. Если создан — его значение уже нормализовано.

[ContactValue](../../app/Modules/Contact/Domain/ValueObjects/ContactValue.php) содержит ContactTypeEnum и нормализует email/телефон/Telegram/VK по разным правилам.

~~~php
// Идея использования, схема упрощена:
$username = UsernameVO::fromString('  Player_123  ');
echo $username->value; // player_123
~~~

**Почему не просто string?** У строк нет гарантий; одинаковое значение могло бы попадать в разные части кода с разными правилами. **VO отличается от Entity:** VO определён значением и правилами; Entity имеет устойчивую идентичность (например, User ID).

## Attributes: что это и что не это

В Laravel 13 Eloquent-модели используют PHP Attributes вида:

~~~php
#[Fillable(['username', 'password', 'status'])]
#[Hidden(['password', 'remember_token'])]
~~~

**В коде:** [User.php](../../app/Modules/Identity/Domain/Models/User.php). Атрибут — метаданные на уровне языка, которые фреймворк может прочитать через reflection. Он не равен HTML-атрибуту, JS-декоратору или проверке авторизации. Fillable ограничивает mass assignment, Hidden скрывает поля в сериализации; ни то ни другое не заменяет permissions и серверную проверку.

## Вопросы с ловушкой

- **«readonly делает объект полностью immutable?»** Нет. Нельзя переназначить readonly-свойство, но вложенный изменяемый объект может менять состояние. VO со строкой безопаснее, чем readonly со ссылкой на mutable DTO.
- **«enum гарантирует бизнес-валидность перехода?»** Нет. Enum ограничивает значения, а VenueBookingLifecycle ограничивает **разрешённые переходы**.
- **«match и switch — одно?»** Нет. match возвращает значение, строго сравнивает и при отсутствии ветки выбрасывает UnhandledMatchError (если нет default).
- **«PHP Attribute сам делает валидацию?»** Нет. Нужен код, который его обрабатывает.
- **«Типы PHP избавляют от SQL-проверок?»** Нет. Нужны и валидация, и ограничения БД.

**Скажи своими словами:** «На примере ContactValue мы централизовали нормализацию разных каналов; это уменьшает разночтения между формами и сервисами. Но VO сам по себе не обеспечивает уникальность в базе».
