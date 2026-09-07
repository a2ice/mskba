# 142 — План

1. [x] Воспроизвести проблему по production screenshot: iOS visual viewport увеличивается при focus на поле.
2. [x] Определить общий слой основной темы для form controls.
3. [x] Добавить mobile-only `font-size: 16px` для текстовых полей, select/textarea и Tom Select input.
4. [x] Не изменять viewport meta и не запрещать ручной pinch-to-zoom.
5. [ ] Пройти PR CI (`php artisan test` + `npm run build`).
6. [ ] Проверить на production iPhone Safari после merge.

## Scope

Изменение глобально для мобильных form controls темы `mskba_dark`, а не только для homepage wizard.
