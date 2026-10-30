# MSKBA App 073 — генерация аватара на dev с production AI credentials

Дата: 2026-10-31. Основание: пользовательская приёмка `/account/participation/player`: на dev генерация возвращает ошибку 502/503, на production уже настроена GitHub-hosted OpenAI pipeline.

## Решение

- Не создаём новые API-ключи и не копируем production `.env`: в проверенном `deploy_dev` используем **существующие GitHub Actions Secrets**, совпадающие с production.
- Ключ `OPENAI_API_KEY` **не попадает на dev VDS** и остаётся только в GitHub-hosted runner workflow `openai-player-generate.yml`. Его наличие проверяется без вывода значения.
- На dev передаются только `MSKBA_GITHUB_AI_TOKEN` → `GITHUB_AI_TOKEN` и `MSKBA_GITHUB_AI_CALLBACK_SECRET` → `GITHUB_AI_CALLBACK_SECRET`. Устанавливаются `PLAYER_CHARACTER_AI_PROVIDER=github_openai`, `GITHUB_AI_REPOSITORY=a2ice/mskba`, `GITHUB_AI_REF=main`.
- На GitHub runner до SSH проводится безопасный preflight: secrets не пусты, токен проверяется по авторизованному `GET /repos/a2ice/mskba/actions/workflows/openai-player-generate.yml` (совместимо с PAT и GitHub App), production workflow читается. **Не делаем платных AI-вызовов в CI**.
- Изменяем только нужные ключи в server-only dev `.env`. Сохраняются `APP_URL=https://dev.mskba.ru`, `APP_ENV=staging`, `APP_KEY`, DB/Redis, кошельки, Telegram и сервисы dev. Файл остаётся `deploy:www-data:640`; временный backup удаляется на успехе и восстанавливается при ошибке, без публикации в репозиторий/артефакты.
- После стандартного `optimize:clear` и пересоздания контейнера PHP-FPM проверяется фактический Laravel gateway (должен быть `BillableGitHubOpenAiPlayerCharacterAiGateway`) и `config('app.url')`; значения секретов не выводятся в логи. Из того же PHP-контейнера делается авторизованный безопасный GET GitHub workflow (без запуска генерации), чтобы дополнительно проверить сеть и правильность токена на самой dev VDS.
- Генерация на dev использует те же общие расходы OpenAI, что production, но создаёт отдельные dev generation-записи и сохраняет медиа в dev. GitHub workflow получает dev manifest/callback URL, подписанные staging-секретом (с тем же значением, что у runner), без смены production webhook.

## Проверки и приёмка

1. PR CI (full PHP tests, JS, Vite) зелёный; dev push CI и verified `deploy_dev` завершились успешно, production не затронут.
2. Dev deployment log: общий preflight по GitHub 200; SSH синхронизировал конфигурацию, фактический Laravel gateway установлен; нет раскрытия значений ключей и токенов.
3. Открыть `/account/participation/player`, загрузить анфас и профиль (или использовать существующие подтверждённые), выбрать форму и нажать «Сгенерировать». Проверить переход pending → processing → completed, появление PNG в личной галерее и сохранение через перезагрузку.
4. Проверить в GitHub Actions **OpenAI player generation** запуск с dev manifest, успешный signed callback на dev, отсутствие чужих изображений/данных в production. Это отдельная **платная** пользовательская E2E-приёмка, не запускаем её автоматически.
5. Проверить безопасность повторного deploy и rollback; секреты ни в `git diff`, ни в release artifact, ни в CI logs.
6. Если секреты repository-level не видны среде `development`, fail-closed до изменения dev. Перенос production-scope секретов вручную не допускается.

## Границы

Только `dev` deployment и документация. Не менять `.github/workflows/deploy.yml`, `main`, production VDS/Telegram, старые средства/кошельки и формат API. Ошибки HTTP 502/503 требуют диагностики по machine `code`, а не маскировки текстом.
