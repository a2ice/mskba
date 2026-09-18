# Task 197 — Acquisition flow: campaign states, landing targets and channel-aware admin UX

## Цель

Развить Task 196 после production-проверки:

- не отдавать 404 для существующей, но недоступной по состоянию кампании;
- добавить настраиваемую посадочную страницу кампании;
- ввести нейтральный campaign entry URL `/go/{code}`;
- показывать только релевантные полям канала настройки;
- привести форму acquisition в соответствие фирменным form controls и вертикальному ритму.

## Поведение кампании

404 используется только для неизвестного `public_code`.

Для существующей кампании:

- выключена вручную → экран «Кампания сейчас неактивна»;
- дата начала в будущем → экран «Кампания ещё не началась» + дата;
- дата окончания в прошлом → экран «Кампания завершена» + дата;
- доступна → продолжается acquisition flow.

Недоступные кампании не создают `acquisition_visits`.

## Посадочная

Canonical campaign entry — `/go/{public_code}`.

Поддерживаемые landing types:

- onboarding — текущий `/join/{code}`, значение по умолчанию;
- home — главная MSKBA;
- venue — выбранная публичная площадка;
- event — выбранное публичное мероприятие;
- sports_section — выбранная активная секция.

Для non-onboarding landing `/go/{code}` сначала создаёт acquisition visit, затем перенаправляет на целевую страницу. После последующей успешной регистрации/авторизации текущий visit связывается с пользователем.

Старые `/join/{code}` сохраняются и также показывают корректные campaign-state screens.

## Канал и физический контекст

Физический контекст не смешивается с landing target.

- QR: площадка, место размещения и добровольная геопроверка доступны.
- Partner / Other: площадка и описание места доступны как optional context, геопроверка выключена по умолчанию и не предлагается как штатное поле.
- Context ads / Social / Referral / Direct: физический блок скрыт, сохранённый physical context очищается.

QR/листовка показываются только для QR campaign.

## UI

- использовать общий `theme::partials.forms.toggle`;
- выровнять label → control → hint spacing;
- сделать отдельные визуальные группы: campaign, период, landing, physical context;
- явно подписать даты как необязательные и объяснить бессрочный режим;
- зависимые блоки переключаются без перезагрузки по channel / landing type.

## Статус

- [ ] domain/migration
- [ ] status pages
- [ ] /go entry
- [ ] configurable landing
- [ ] channel-aware form
- [ ] form polish
- [ ] tests/docs/CI
