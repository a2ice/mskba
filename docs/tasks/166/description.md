# Task 166 — сохранять целевой redirect после авторизации

## Контекст

Гость на публичной странице площадки нажимает «Забронировать» и попадает на защищённый `/events/create/wizard?...`. Middleware `auth` корректно отправляет его на `/login` и Laravel сохраняет исходный URL как `url.intended`.

На production обнаружена регрессия: после успешного входа через VK ID пользователь оставался на `/login`, вместо возврата к бронированию. Параллельно нужно проверить одинаковую семантику для пароля, регистрации, Telegram Web Login и Telegram bot login.

## Причина

Standalone partial VK ID формировал ссылку `/auth/vk?redirect_to=<текущий URL>`. На странице входа текущим URL является `/login`. `SafeAuthenticationRedirectResolver` отдавал приоритет явному `redirect_to` и одновременно извлекал `url.intended` из сессии на старте внешнего OAuth-flow. В результате VK flow сохранял `/login` как конечную точку и исходная цель бронирования терялась.

## Решение

- `/login` и `/register` не должны становиться post-auth destination.
- Разрешены только относительные или same-origin return URL; внешние URL и protocol-relative URL отклоняются.
- Внешние auth-flow (VK и Telegram bot) читают intended URL без преждевременного удаления. Intended очищается только после успешной авторизации.
- Password login, registration и Telegram Web Login используют единый resolver и потребляют intended после успеха.
- Статическая VK-кнопка на standalone `/login` больше не передаёт текущую `/login` как `redirect_to`; modal-flow продолжает передавать собственную целевую страницу через существующий JS.
- Если у гостя есть безопасный pending redirect, страницы входа и регистрации показывают уведомление о том, что после успешной авторизации пользователь вернётся к нужному действию. Для `/events/create/wizard` подпись — «Бронирование площадки». Полный query string пользователю не раскрывается.

## Проверяемые каналы

1. логин/пароль;
2. обычная регистрация;
3. VK ID OAuth/PKCE;
4. Telegram Web Login widget;
5. Telegram bot login challenge (даже если кнопка bot fallback сейчас скрыта в UI).

## Acceptance criteria

- Гость, открывший защищённый booking wizard, после успешной авторизации возвращается на исходный URL вместе с `venue_id`/`venue_court_id` и прочими query-параметрами.
- VK ID не сохраняет `/login` как return destination.
- Отмена/ошибка внешнего VK flow не стирает pending intended URL: повторный вход всё ещё может продолжить исходное действие.
- Telegram Web Login и Telegram bot login возвращают тот же intended URL.
- Password login и регистрация возвращают тот же intended URL.
- После успешной авторизации `url.intended` очищен и не влияет на будущие входы.
- Unsafe/external redirect не принимается.
- На `/login` и `/register` при наличии pending target показано понятное уведомление, для бронирования явно указано «Бронирование площадки».
- Без pending target интерфейс входа/регистрации остаётся прежним.
