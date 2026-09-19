# 210 - Синхронизировать safe area Telegram Mini App и Android navigation bar

## Оригинальное описание

В Telegram Mini App верхние системные контролы Telegram перекрывают toast и часть интерфейса, а на Android системная нижняя навигация наслаивается на мобильный нижний bar MSKBA. Ранее Telegram имел отдельный safe-отступ, но текущее поведение стало недостаточным.

## Подробное описание

Нужно восстановить единый контракт safe area для мобильного shell:

- учитывать browser `env(safe-area-inset-*)`;
- в Telegram дополнительно учитывать `Telegram.WebApp.safeAreaInset` и `Telegram.WebApp.contentSafeAreaInset`;
- поддержать CSS-переменные Telegram `--tg-safe-area-inset-*` и `--tg-content-safe-area-inset-*` как штатный источник;
- синхронизировать значения из SDK после загрузки и при событиях `safeAreaChanged` / `contentSafeAreaChanged`;
- toast в Mini App располагать ниже верхних контролов Telegram;
- нижний mobile primary bar, его stats-блок, отступ контента и раскрытое мобильное меню поднимать над системной нижней областью Android/iOS;
- сохранить browser fallback и старый минимальный Telegram top-offset для клиентов без новых safe-area API.

## Проверка

- CI должен пройти PHP tests и production frontend build;
- в обычном мобильном браузере нижний bar сохраняет прежнее положение с browser safe area;
- в Telegram Android нижний bar находится выше системных кнопок/gesture area;
- в Telegram toast появляется ниже верхней панели Telegram;
- изменение safe area при смене состояния WebView обновляет CSS без перезагрузки.

## Результат

Введён единый набор `--app-safe-area-inset-*`: в обычном браузере он использует CSS `env(...)`, а в Telegram объединяет browser safe-area, штатные `--tg-*` переменные и значения WebApp SDK. `telegram-mini-app.js` считывает `safeAreaInset` / `contentSafeAreaInset` после загрузки SDK и пересинхронизирует их по Telegram safe-area events.

Toast привязан к Telegram content-safe top. Общий mobile primary bar, stats, резерв контента, мобильное меню и связанные нижние fixed-компоненты переведены на общий bottom safe inset; это оставляет кликабельную область выше Android navigation bar и iOS gesture area. Старый минимальный Telegram top fallback сохранён для совместимости.

Релевантная автоматическая проверка выполняется PR CI: backend suite и production frontend build являются gate перед merge в `main`.
