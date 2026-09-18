# Task 200 — Редактируемые базовые поля acquisition flyer

## Цель

До полноценного шаблонизатора дать администратору возможность редактировать текстовые поля встроенной A4-листовки прямо в карточке acquisition campaign.

## Архитектура

Поля описываются в trusted template definition как схема:

- key;
- label;
- type;
- max;
- default или dynamic default source;
- UI group.

Кампания хранит только overrides в `metadata.flyer_content`.

Blade не содержит значения по умолчанию и не читает campaign metadata напрямую: он получает уже разрешённый `templateFields` через context factory.

Это промежуточный слой перед DB/editor templates: будущий шаблонизатор сможет использовать тот же набор logical field keys и заменить источник definition/overrides без изменения PDF renderer.

## UI

Форма располагается в блоке «QR и A4-листовка» над кнопками Preview/PDF/QR.

Редактируются:

- eyebrow;
- две строки hero-заголовка;
- hero lead;
- отображаемое название площадки;
- подпись площадки;
- три строки CTA-заголовка;
- три CTA-шага;
- подпись под QR.

Есть «Сохранить текст» и «Сбросить по умолчанию».

## Статус

- [x] template field schema;
- [x] campaign overrides;
- [x] admin UI;
- [x] flyer context/template;
- [x] tests/smoke;
- [x] CI;
- [ ] merge/deploy.
