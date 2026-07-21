# 043 - Сохранять Telegram-контекст на внутренних страницах

## Оригинальное описание

На внутренних страницах Telegram Mini App недостаточен верхний отступ и не видны логотип и иконка профиля, хотя в обычном мобильном браузере шапка отображается.

## Подробное описание

Признак Telegram WebView должен сохраняться в Laravel-сессии после входа через `/telegram` и использоваться общим layout на последующих страницах. Это presentation-only контекст: права и авторизация по-прежнему определяются только проверенными Telegram init data и обычной Laravel-аутентификацией.

Telegram SDK и safe-area должны работать на внутренних страницах, однако AJAX-аутентификация запускается только на entry page. Верхний отступ контента должен учитывать фактическую высоту fixed header. Обычная мобильная версия не должна измениться.

## Проверка

- feature-тест сохранения Telegram-контекста в сессии;
- feature-тест отсутствия повторного auth bootstrap;
- feature-тест обычного mobile web-контекста;
- frontend production build;
- полный backend test suite.

## Результат

Telegram presentation context сохраняется в Laravel-сессии и применяется общим layout на внутренних страницах. SDK продолжает обновлять safe-area, фактическая высота fixed header синхронизируется через `ResizeObserver`, а auth bootstrap остаётся только на `/telegram`. Обычные мобильные страницы без Telegram-сессии не изменены.

Проверки пройдены: Telegram feature suite и полный backend suite (201 тест, 1157 assertions), production-сборка Vite и `git diff --check`.
