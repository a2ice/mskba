# 142 — Убрать автоматический zoom мобильной страницы при фокусе на полях

## Контекст

После production smoke-check нового homepage wizard на iPhone обнаружено типичное поведение iOS Safari: при фокусе на текстовом поле с `font-size` меньше 16px браузер автоматически увеличивает visual viewport. В результате popup и окружающий интерфейс визуально становятся крупнее, хотя пользователь не выполнял pinch-to-zoom.

Проблема не специфична для homepage wizard и может проявляться на любых формах основной темы `mskba_dark`, включая динамические контролы Tom Select.

## Цель

Убрать автоматический iOS focus zoom глобально для мобильных форм основной темы, не запрещая пользователю ручное масштабирование страницы.

## Решение

В общем `form-controls.css` для экранов до 767px текстовые form controls получают минимальный `font-size: 16px`:

- text/search/email/tel/url/password/number;
- date/datetime-local/month/time/week;
- `select`;
- `textarea`;
- внутренний `input` Tom Select (`.ts-control input`);
- `input` без явного `type`.

Нетекстовые controls (`checkbox`, `radio`, `range`, `color`, `file`, hidden и т.п.) правило не затрагивает.

Не используются `user-scalable=no` или `maximum-scale=1`, поэтому ручной pinch-to-zoom и доступность страницы сохраняются.

## Проверка

На iPhone Safari:

1. открыть любую форму с текстовым полем;
2. сравнить масштаб до и после focus + появления клавиатуры;
3. убедиться, что visual viewport не увеличивается;
4. отдельно проверить Tom Select, в том числе выбор метро в homepage wizard;
5. убедиться, что ручной pinch-to-zoom остаётся доступен.

Дополнительно перед merge требуется штатный PR CI (`php artisan test` + `npm run build`).
