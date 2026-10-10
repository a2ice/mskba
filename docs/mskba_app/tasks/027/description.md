# 027 — контекстные «Действия» и помощь FAQ (staging pilot)

## Контекстные действия

`ContextSubmenuResolver` выбирает наиболее конкретное правило: `exact` имеет приоритет над `pattern`, среди регулярных выражений выигрывает совпадение с большей длиной. В `config/submenu.php` правила имеют ключи, поддерживают `routes` (фильтр по имени Laravel route), `parent`, `include_parent` (по умолчанию `false`). Если для дочернего URL нет своего правила, наследуется совпадающее правило раздела; если правило есть, набор заменяется; при `include_parent=true` наборы объединяются — сперва дочерний, затем родительский.

- `/venues`: `Создать`, `Найти`;
- `/venues/{alias}` (строго route `venues.show`): `Забронировать`, `Найти похожие`;
- В качестве действий используются `MenuHandler`, безопасные кнопки `placeholder` с доступным native `<dialog>`, без фиктивных маршрутов;
- Постоянные `Назад`, `Поделиться`, `Помощь` остаются в меню.

## Помощь

`Помощь` открывает native modal с контекстом из `FaqContextResolver`: на маршрутах площадок используется `venues`, на остальных маршрутах — известные разделы. Для дополнительных соответствий меняем `app/Presentation/Navigation/FaqContextResolver.php`. API `GET /faq/help-content` (только опубликованные материалы) возвращает дерево виртуальных разделов, статьи, breadcrumbs и отрендеренный через штатный `ContentBodyRenderer` очищенный HTML.

FAQ sections are virtual and derived from stable `faq.*` system keys or matching thematic tags configured in `config/support.php`. The compact searchable combobox has a dropdown arrow and a clear control on its right; breadcrumbs are one line (`FAQ / Section / Article`) and cards contain only question titles without short summaries. The FAQ breadcrumb resets to all top-level sections.

## Вопрос в поддержку

Внизу помощи расположен закрытый по умолчанию блок `Задать вопрос`: обязательные тема и текст (10–5000 символов), `SUPPORT_EMAIL` (по умолчанию `support@mskba.ru`) для обращения по почте. Авторизация через стандартный `AuthDialog.vue`: для логина добавлен опциональный `inline_auth`, который при отсутствии обязательного privacy onboarding не делает redirect, обновляет CSRF token и отправляет событие `mskba:auth:success`, после которого попап помощи снова открывается и форма становится доступна. У обычных входов/регистрации и при обязательном onboarding остаётся штатный redirect. Внешние способы OAuth/VK/Telegram могут требовать штатного перехода; их обработка in-place — отдельная интеграция.

`POST /faq/questions` uses authenticated, rate-limited (`5/10`) input validation and directly sends `SupportQuestionMail` to `config(support.email)`. It does not persist questions in PostgreSQL. `log`/`array` mail transports are rejected with HTTP 503, and actual SMTP transport failure also returns 503 without claiming delivery. Accepted SMTP messages may still be rejected later by remote mail infrastructure.

Staging may send real support mail once dedicated SMTP is configured on VDS. As of 2026-10-10, staging still has `MAIL_MAILER=log` without SMTP credentials: the form explicitly fails rather than silently logging. Existing production SMTP credentials must not be copied into staging without user approval. Production is not modified.

На staging `deploy_dev` теперь после миграций и безопасного superadmin seeder также идемпотентно вызывает `FaqContentSeeder`, чтобы новые dev-базы получали семь существующих системных FAQ. Seeder не меняет отредактированные материалы и не восстанавливает soft-deleted записи.

Изменения выполняются только в `mskba_app`; production `mskba_dark` не меняется. Регрессионная проверка: unit resolver; feature FAQ catalog + privacy + auth + support; `npm run build`; CI + staging HTTP и созданные seeded FAQ.

## Follow-up UI refinement 2026-10-10

Visible field labels have been removed in favor of placeholders with accessible `aria-label` values. FAQ breadcrumbs no longer have duplicated navigation text; dropdown arrow precedes the clear control and all are vertically centered. Historical `support_questions` table is removed by a reversible follow-up migration that refuses to delete any nonempty table. Live staging was verified to have zero rows before this change.
