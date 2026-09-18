# 193 — Мобильный lifecycle tooltip

## Причина

На touch-устройствах браузер синтезирует hover/focus при tap. Из-за этого общий floating tooltip мог остаться видимым после активации action-кнопки, особенно когда действие сразу меняло DOM/состояние интерфейса. Наиболее заметный пример — кнопка «Свернуть» в modal: окно уходило в tray, а tooltip «Свернуть» оставался поверх страницы до следующего tap.

Похожий риск существовал и у отдельного tooltip-кода сводки главной страницы.

## Решение

- Общий `tooltips.js` различает устройства с реальным hover и touch/coarse pointer.
- Desktop/mouse сохраняет hover/focus lifecycle.
- На touch отдельная кнопка `?` и неинтерактивный tooltip-source переключают подсказку по tap; повторный tap или tap вне источника закрывает её.
- Action-элементы (`button`, `a`, form controls, `[role=button]`) на touch не удерживают tooltip: первый tap выполняет действие, а floating tooltip очищается.
- `modal:minimized` и `modal:closed` принудительно закрывают активную подсказку, поэтому hidden source не может оставить orphan tooltip.
- Аналогичная touch-логика применена к `site-summary.js`.
- Контракт задокументирован в `docs/specification.md`.

## Проверка

Production deploy выполняет Vite build и HTTP health-check. Дополнительно требуется мобильный smoke-check: action-кнопка с tooltip не оставляет подсказку после действия; информационная подсказка открывается/закрывается tap-ом; tap вне источника закрывает tooltip.
