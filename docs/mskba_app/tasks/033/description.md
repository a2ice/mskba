# 033 — Анимация успешного действия во всех MSKBA App модалках

**Статус:** на локальной визуальной приёмке. **Дата:** 2026-10-13.

## Согласованная задача

В ответ на референсы успешного попапа добавлять над текстом успешного результата фирменную анимацию: белое баскетбольное кольцо, мяч, попадание и зелёная галочка. Источник — загруженный пользователем `mskba-success-v5-earlier-check-tight.gif` (SHA-256 `0daf03c3f84b973e75785edb9efb3483704ae13b5e1002157bf16b99723a3f68`, 186×156px, 18 кадров, 1040 мс). На локальном Mac найден точный файл с этой контрольной суммой.

## Контракт общего UI

- Только `mskba_app`. Появляется при **явном подтвержденном результате**: `.mskba-modal[data-surface-tone="success"]`, совместно с утверждённой зелёной подсветкой, без дополнительного JS на странице. Не показывать для `neutral`, загрузки, до ответа сервера или после закрытия.
- Одна анимация над текстом внутри прокручиваемой `mskba-modal__body`, без захвата кликов/focus и без изменения header/footer или границ; адаптивный размер с сохранением пропорций. Не дублировать анимацию в отдельных секциях/форматах модалок.
- Однократный показ, финальная галочка или красный крестик остаются видимыми. При `prefers-reduced-motion: reduce` — статический финальный кадр соответствующего статуса вместо GIF.
- Обновить дизайн-гайдлайн и демонстрационный каталог с вариантом success/error/neutral.
- Без изменений в legacy `mskba_dark`, API, логике подтверждений, `origin/dev`, CI/deploy. Предыдущие незакоммиченные изменения в ветке `dev` оставить нетронутыми.

## Проверки и приёмка

- [x] GIF без бесконечного цикла, PNG последнего кадра, размеры/прозрачность/длительность оригинала.
- [x] Chrome: success/error с компактной анимацией над текстом, neutral без нее, единый градиент; desktop screenshot. Мобильная визуальная приёмка — у пользователя.
- [x] Reduced motion: статичная галочка/крестик, без интерактивного блока.
- [x] Vite, Blade, git diff --check, JS syntax, 76 PHPUnit / 803 assertions — PASS.
- [ ] Приёмка пользователем на localhost до commit/push.

Контракт: [Modal 1.1](../../../specification/ui-design-system.md#актуализация-2026-10-08-modal-11--согласованное-общее-правило), [tone 031](../031/description.md).

## Revision 2026-10-13: compact success and error outcomes

The user requested an exact 2x reduction in animation display size and a matching error image. Both `data-surface-tone=success` and `data-surface-tone=error` now show one centered decorative animation above the dialog body text. The size is **58x49 CSS pixels** on all viewport widths. Neutral and loading states have none.

- Success: `mskba-success-v5-earlier-check-tight.gif`, basket hit and green check.
- Error: `mskba-error-v2-right-rim-hit.gif`, ball deflects from the right rim and ends with red cross.
- Original source GIFs came from the user's Downloads. Each has 18 frames and 1040 ms total animation duration. We removed only the NETSCAPE infinite-loop extension and confirmed pixel-identical RGBA frames with Pillow. The GIF retains its final frame. Separate PNG final frames serve `prefers-reduced-motion: reduce`.
- Common implementation: `resources/themes/mskba_app/css/modal.css`, selector `.mskba-modal[data-surface-tone] > .mskba-modal__body::before`; no duplicated markup in specific dialogs.
- `account-contacts-add.js` already sets the root tone. `account-direct-messages.js` now explicitly sets error on request rejection and success only after an actual successful response, resetting to neutral on new opens.
- Browser preview: `http://127.0.0.1:8014/catalog/surface-glow.html?tone=success` or `?tone=error`.
- Still **local-only**, no commit / push / PR / deployment before visual acceptance.


## Выпуск после приёмки

Пользователь подтвердил выпуск визуального пакета 031–034 в ветку `dev` (2026-10-13). Предыдущая пометка «local-only / no push» отражает этап предварительной приёмки и не запрещает согласованную публикацию. Модалки и анимации сохраняют контракты дизайна из этого документа.
