# 001 — Домен и журнал полевого подключения

## Цель

Добавить минимальный orchestration/audit слой для очного подключения площадки,
не создавая параллельную модель владения.

## Работы

- ввести `VenueFieldOnboarding` или эквивалентную сущность;
- связать её с Venue, agent user, representative user, ownership claim и reviewer;
- определить lifecycle визита и outcome;
- хранить timestamps ключевых этапов;
- разрешить optional acquisition source/campaign;
- писать actor/audit для изменений;
- не выводить из этой сущности никаких venue permissions;
- предусмотреть данные для будущей конверсии и мотивации агента.

## Инвариант

Права представителя появляются только из существующего
`VenueOwnershipClaim → VenueOwnership → ContractMembership`.
