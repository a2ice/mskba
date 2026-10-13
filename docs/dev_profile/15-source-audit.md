# 15. Карта аудита кода и границы утверждений

[← Оглавление](README.md) · [В начало](README.md)

## Что было проверено

Ветка локальной рабочей копии: **dev**; базовый commit на момент начала аудита: **abeee380** (2026-10-12). Дата проверки: **2026-10-13**. Просмотрены composer/package, Compose/CI, app/Modules, ключевые handlers/services/events/listeners, Eloquent-модели, миграции, Vue/JS entry points, Reverb и соответствующие Feature/Unit tests. Это **широкий статический аудит**, не runtime нагрузочное тестирование или полная line-by-line ревизия всех классов.

## Карта доказательств

| Проверяемое утверждение | Источник | Статус |
| --- | --- | --- |
| Laravel ^13.8 и PHP ^8.3 | [composer.json](../../composer.json) | В коде |
| PHP 8.4 / Node 22 в CI | [ci.yml](../../.github/workflows/ci.yml) | В коде |
| PostgreSQL 17 / Redis 7 / Reverb в Compose | [compose.yaml](../../compose.yaml) | В коде; фактический uptime не проверен |
| Domain / Application / Infrastructure / Presentation | [app/Modules](../../app/Modules) | В коде |
| PHP enum + label/roles | [UserSystemRoleEnum](../../app/Modules/Identity/Domain/Enums/UserSystemRoleEnum.php) | В коде |
| PHP Attributes | [User](../../app/Modules/Identity/Domain/Models/User.php) | В коде |
| readonly VO и normalizer | [UsernameVO](../../app/Modules/Identity/Domain/ValueObjects/UsernameVO.php), [ContactValue](../../app/Modules/Contact/Domain/ValueObjects/ContactValue.php) | В коде |
| Event/Listener после подтверждения контакта | [Identity provider](../../app/Modules/Identity/Infrastructure/Providers/IdentityCanonicalizationServiceProvider.php), [Notification provider](../../app/Modules/Notification/Infrastructure/Providers/NotificationServiceProvider.php) | В коде |
| Strategy подтверждения контакта | [Contract](../../app/Modules/Contact/Application/Contracts/ContactVerificationStrategy.php), [email strategy](../../app/Modules/Contact/Application/Strategies/EmailContactVerificationStrategy.php) | В коде |
| Reward mechanism registry | [RewardMechanismRegistry](../../app/Modules/Rewards/Application/Services/RewardMechanismRegistry.php) | В коде |
| Транзакционная регистрация | [RegisterUserHandler](../../app/Modules/Identity/Application/UseCases/RegisterUserHandler.php) | В коде |
| Идемпотентность брони | [IdempotentVenueBookingCommand](../../app/Modules/VenueBooking/Application/Services/IdempotentVenueBookingCommand.php) | В коде |
| Критичные row locks | [LockedVenueBooking](../../app/Modules/VenueBooking/Application/Services/LockedVenueBooking.php) | В коде |
| Outbox | [VenueBookingOutbox](../../app/Modules/VenueBooking/Application/Services/VenueBookingOutbox.php), [dispatcher](../../app/Modules/VenueBooking/Application/Services/VenueBookingOutboxDispatcher.php) | В коде |
| Финансовая идемпотентность | [IdempotentWalletMutation](../../app/Modules/Finance/Application/Services/IdempotentWalletMutation.php) | В коде |
| Unique business fact у rewards | [migration](../../database/migrations/2026_10_06_160100_create_reward_grants_table.php) | В коде |
| Redis search TTL + version | [VenueSearchCache](../../app/Modules/Venue/Application/Services/VenueSearchCache.php) | В коде |
| Договорная ACL с exists | [VenueMembershipAccess](../../app/Modules/Venue/Application/Services/VenueMembershipAccess.php) | В коде |
| Vue Composition API | [RegistrationWizard.vue](../../resources/themes/mskba_app/js/components/RegistrationWizard.vue) | В коде |
| Inertia только в preview | [app.js](../../resources/themes/mskba_app/js/app.js), [inertia.js](../../resources/themes/mskba_app/js/inertia.js) | В коде |
| Reverb + private broadcasting | [realtime.js](../../resources/js/realtime.js), [channels.php](../../routes/channels.php) | В коде |
| HTTP reconciliation | [notification-counts.js](../../resources/themes/mskba_app/js/notification-counts.js) | В коде |
| dev и prod workflow | [ci.yml](../../.github/workflows/ci.yml), [deploy.yml](../../.github/workflows/deploy.yml) | В коде |
| Модерация профиля и заявочные треды | [ProfileChangeRequestService](../../app/Modules/Identity/Application/Services/ProfileChangeRequestService.php), [ThreadAccess](../../app/Modules/Notification/Application/Services/ProfileRequestThreadAccess.php) | **WIP: незакоммичено** |

## Что требует дополнительной проверки перед публичной формулировкой

- Реально используемые переменные окружения на dev/prod, uptime queue workers/Reverb/Gotenberg.
- Измеренные SQL-метрики до/после оптимизаций, benchmark p95, нагрузка Redis.
- Какие конкретно функции включены на production (feature flags), а какие подготовлены.
- Чей личный вклад в конкретные решения: код подтверждает факт существования, не авторство.
- Реальные результаты CI и deploy каждого коммита на момент интервью: GitHub Actions данные меняются.
- WIP-модерация, новые request threads и UI-правки: локальное дерево dev имеет незакоммиченные изменения.

## Методология обновления

1. Перед интервью сравни текущий commit и composer.json с этим срезом; при релизах обнови версии.
2. Открой примеры по ссылкам и проверь, что не изменились классы/семантика.
3. Если есть замеры производительности — добавь отдельный раздел с методикой и цифрами.
4. Не использовать эти учебные главы как source of truth при внедрении: основные документы [project](../project.md) и [specification](../specification.md) первичны.
5. Не переносить WIP в раздел «production» без CI/deploy-подтверждения.
