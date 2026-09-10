# План Task 166

- [x] Воспроизвести и локализовать потерю return URL в VK ID flow.
- [x] Проверить текущую семантику password, registration, Telegram Web и Telegram bot login.
- [x] Расширить `SafeAuthenticationRedirectResolver` безопасным peek/preserve API и запретом auth-entry destinations.
- [x] Не потреблять `url.intended` на старте внешнего VK/Telegram bot flow.
- [x] Очищать intended только после успешной внешней авторизации.
- [x] Убрать `/login` из статического `redirect_to` VK-кнопки.
- [x] Добавить понятное уведомление о pending redirect на `/login` и `/register`.
- [x] Добавить regression coverage для password, registration, VK ID, Telegram Web и Telegram bot flows.
- [ ] Запустить полный PR CI и исправить найденные регрессии.
- [ ] Перед merge сверить ветку с актуальным `main` и параллельной работой.
- [ ] Влить PR в `main` только после зелёного CI.
- [ ] Дождаться зелёного production deploy и выполнить smoke-check.
- [ ] Отметить Task 166 завершённой в документации после production-проверки.
