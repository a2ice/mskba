# 09. Frontend: Vue 3, Inertia, Blade и Vite

[← Оглавление](README.md) · [Далее →](10-websocket-reverb.md)

## Гибридная архитектура — фактическая картина

Не говори «всё на Vue SPA»: **основная тема содержит Blade + модульный JS**, отдельные интерактивные острова на Vue 3, а Inertia bootstrapped только для изолированного ui-preview route — это прямо отмечено в [app.js](../../resources/themes/mskba_app/js/app.js). Конфигурация [Inertia](../../resources/themes/mskba_app/js/inertia.js) есть, но не означает полного переноса портала.

~~~text
Laravel route / Controller
          │
          ├─ Blade HTML / темы + JS-модули + частичные Vue mounts
          │       ├─ регистрация → Vue 3 Wizard
          │       ├─ контакты / профиль → JS формы и fetch
          │       └─ уведомления → Echo + DOM/toasts
          │
          └─ изолированный ui-preview → Inertia Vue page
~~~

## Почему такой подход

Много существующих страниц и серверных бизнес-процессов — их не нужно переписывать ради модного стека. Vue используем для сложных интерактивных состояний: формы со множеством шагов, модальные окна и динамический выбор данных. Blade остаётся полезным для серверного рендера, повторного использования шаблонов и интеграции с Laravel.

**Компромисс:** сосуществование Blade, Vue, независимых DOM-модулей и нескольких тем требует правил владения DOM, подключения ассетов и CSS-дизайн-системы; иначе появляются коллизии.

## Vue 3 в настоящем коде

[RegistrationWizard.vue](../../resources/themes/mskba_app/js/components/RegistrationWizard.vue):
- Composition API и script setup;
- defineProps для входных данных, defineEmits для событий submit/login/step-change;
- ref/reactive для состояния, watch для ошибок сервера;
- lifecycle hooks onMounted/onBeforeUnmount для подписки/очистки событий;
- отдельная конфигурация шагов [registrationWizardConfig.js](../../resources/themes/mskba_app/js/components/registrationWizardConfig.js);
- WizardShell переиспользует механизм шага/ошибок.

**Почему не всё хранить в DOM?** Реактивное состояние отражает реальное состояние формы и позволяет не разбрасывать состояние по querySelector-цепочкам. При этом сервер остаётся окончательным источником валидации и прав.

## Два потока данных

~~~text
Vue/JS UI → fetch/form POST → Laravel controller
        ← errors/JSON/redirect ← UseCase ← PostgreSQL

WebSocket event → Echo → notification-counts.js
                           ↓
                       badge + toast
                           ↓
                     PATCH mark read
                           ↓
                   server sync response
~~~

[notification-counts.js](../../resources/themes/mskba_app/js/notification-counts.js) защищается от гонки: новые push-события и новая синхронизация не должны быть перезаписаны старым HTTP-ответом. Есть revision и syncSerial. Это отличный UX/consistency-кейс для собеседования.

[notification-toasts.js](../../resources/themes/mskba_app/js/notification-toasts.js) ограничивает число одновременно видимых карточек (3), защищает от двойного клика, отмечает сообщение прочитанным через PATCH и разрешает только same-origin HTTP(S) action URL.

## Inertia — когда и зачем

Inertia соединяет Laravel server-side routing/controller и клиентские Vue-компоненты без обязательного отдельного REST API: Laravel возвращает Inertia page props, Vue её рендерит, клиентская навигация может быть без полной перезагрузки. Но в MSKBA это **ограниченный подготовленный слой**, а не основная архитектура всего портала.

**Сравнение:** Laravel + Blade — полноразмерный HTML-ответ; Laravel + Inertia — состояние страницы как props; отдельная REST SPA — отдельный API-контракт и жизненный цикл роутера/авторизации.

## Vite и стили

[vite.config.js](../../vite.config.js) перечисляет точки входа нескольких тем, подключает vue(), laravel() и Tailwind. Vite dev server обеспечивает разработку, production build отдаёт версионированные бандлы. В проекте есть [дизайн-система MSKBA App](../specification/ui-design-system.md): токены, компоненты, стили и единое поведение десктоп/мобильного/Telegram Mini App.

## Вопросы

**«Vue Options API или Composition API?»** В RegistrationWizard — Composition API и script setup. Плюс — логика по назначению (steps, errors, events), реиспользуемые composables при росте.

**«Props vs Emits?»** Props от родителя вниз, emit — событие от дочернего компонента вверх; изменение внешних props напрямую — плохая идея.

**«Что с CSRF?»** Мутирующие запросы защищаются Laravel; в JS PATCH явно отправляется X-CSRF-TOKEN. Авторизацию всё равно проверяет backend.

**«Почему не SSR Vue?»** Пока часть приложения на Blade, нужен баланс бюджета миграции и UX. SSR/полная SPA требуют реального обоснования.

**«Что такое XSS в уведомлениях?»** Не вставляем HTML из серверного payload как innerHTML; в toasts используется textContent и проверка action URL по origin/protocol.
