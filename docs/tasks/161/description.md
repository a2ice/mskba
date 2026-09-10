# 161 — Добавить индикатор достоверности и развить блок активностей площадки

> Originally planned as historical Task 156 before task numbering diverged.

## Контекст и проблема

Публичная страница площадки уже получает `current/upcoming` из
`GET /venues/{venue}/activities` и показывает раздел «Игры и мероприятия».
Пользователь, однако, не понимает, насколько сведения подтверждены ответственным
владельцем, а карточки должны устойчиво раскрывать только доступные публичные данные.

## Бизнес-логика достоверности

Каноническое правило:

```text
warning =
    no active ownership
    OR maintenance commitment not accepted
    OR maintenance_score < 70

trusted =
    active ownership
    AND maintenance commitment accepted
    AND maintenance_score >= 70
```

Это именно OR для warning. В частности, `commitment=true, score=30` остаётся
недостоверным, как и `commitment=false, score=100`. Порог 70 включительный.

Активность владения определяется существующим договорным механизмом действующего
OWNER membership, а не frontend-эвристикой или одним `active_marker`.

## Scope

- серверный resolver/view model достоверности на основе активного ownership;
- расширение существующего activities endpoint без нового activity subsystem;
- warning и action «Уточнить» только для недостоверного состояния;
- доступный modal-заглушка без fake request и лишнего backend endpoint;
- полноценное отображение уже доступных type/title/status/time/teams/score/links;
- regression-тесты матрицы доверия, current/upcoming и privacy.

## Out of scope

- community verification flow и отправка запроса уточнения;
- автоматический пересчёт maintenance score;
- раскрытие закрытых/черновых мероприятий;
- переписывание Event/Game/Tournament доменной модели.

## Data и domain source

Resolver сначала получает действующий OWNER membership через
`VenueMembershipAccess`, затем связывает его с `VenueOwnership` по
`contract_membership_id`. Только такое владение может быть trusted. Поля качества
берутся из Task 160; при отсутствии связанной активной записи результат недостоверен.

## Permissions, security и privacy

Endpoint остаётся публичным, поэтому возвращает подробности и ссылки Event только
для `PUBLISHED + PUBLIC`. Private/unpublished Event не должен появляться ни в
`current`, ни в `upcoming`; занятый слот может существовать отдельно без раскрытия
названия, участников или URL. Подтверждённый Tournament остаётся в текущем публичном
контуре согласно существующему контракту его публичной страницы.

## API

`GET /venues/{venue}/activities` дополняется серверным состоянием доверия, например:

```json
{
  "information_trusted": false,
  "information_warning": true,
  "current": [],
  "upcoming": []
}
```

Frontend не вычисляет ownership condition самостоятельно. Существующие поля activities
и realtime refresh сохраняются обратно совместимыми.

## UI

Под заголовком раздела при `information_warning=true` показываются текст
«Данная информация может быть не актуальной» и «Уточнить». Action открывает
согласованный с темой dialog с сообщением о будущей доступности функции; dialog
закрывается кнопкой, backdrop и Escape, с корректными ARIA-атрибутами и возвратом
фокуса. При trusted-состоянии отсутствуют и warning, и action.

Карточки используют существующий renderer и показывают доступные тип, заголовок,
статус, интервал, команды/счёт и публичные ссылки; live snapshot/realtime не ломается.

## Performance и конкурентный доступ

Resolver выполняет ограниченное число индексируемых запросов для одной площадки;
Event/Game/Team и Tournament relations продолжают eager loading, чтобы не добавить
N+1. Только чтение — новые блокировки и риск deadlock отсутствуют.

## Acceptance criteria

- полная матрица OR и включительный threshold 70 соблюдены;
- «Уточнить» существует только при warning и открывает доступный modal;
- `current` и `upcoming` продолжают работать;
- публичное опубликованное мероприятие имеет корректную ссылку;
- private/unpublished activity не раскрывается;
- карточки показывают все доступные подробности без выдуманных данных;
- desktop/mobile и существующая venue page не регрессируют.

## Tests

- no ownership, false+100, true+0, true+60, true+70, true+100;
- warning/action contract;
- public/published URL и отсутствие private/unpublished данных;
- current/upcoming;
- существующие venue regression tests и frontend build.

## Implementation progress

- [x] восстановлено каноническое описание и выбран свободный номер;
- [x] подтверждено наличие существующего activities endpoint/renderer;
- [x] server trust resolver и API contract;
- [x] warning/modal и использование существующего полного renderer карточек;
- [x] privacy/regression tests;
- [ ] PR, CI, merge и production smoke.

## PR и deployment

- Branch: `feature/161`, создана от `main` после merge Task 160
- PR: `#159`
- Merge: ожидается
- Production deploy: ожидается

## Проверки

- targeted: `VenueActivityFeedTest` — 6 tests / 39 assertions;
- full backend: 774 tests / 5429 assertions;
- PHP formatter: успешно;
- frontend production build: успешно.
