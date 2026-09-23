# Task 242. OpenAI player generation через GitHub Actions

## Цель

Заменить Yandex как provider по умолчанию для 2D-персонажа, не вызывая OpenAI
напрямую из RU VDS. VDS dispatch-ит GitHub Actions, GitHub-hosted runner вызывает
OpenAI Images Edit API и возвращает результ по защищённому callback. Yandex остаётся
fallback/rollback.

## Реализация

- добавлен provider `github_openai` и `workflow_dispatch` workflow;
- добавлена асинхронная generation model с `pending|processing|completed|failed`;
- private face references выдаются runner только по короткоживущим signed URLs;
- callback защищён timestamp tolerance, SHA-256 и HMAC shared secret;
- terminal callback идемпотентен и сериализован DB row lock;
- result PNG хранится в private storage и отдаётся только владельцу;
- frontend получает HTTP 202 и опрашивает status endpoint;
- deploy включает provider только при наличии GitHub token и callback secret.

## Контракт секретов

GitHub Actions repository secrets:

- `OPENAI_API_KEY` — используется только GitHub-hosted runner;
- `MSKBA_GITHUB_AI_TOKEN` — fine-grained token с `Actions: write` для запуска workflow с VDS;
- `MSKBA_GITHUB_AI_CALLBACK_SECRET` — shared HMAC secret для callback.

OpenAI key не требуется на VDS для `github_openai`. На VDS deploy передаёт только
`GITHUB_AI_TOKEN` и `GITHUB_AI_CALLBACK_SECRET` как server env.

## Проверка

- feature tests покрывают dispatch, manifest, private references, callbacks, owner-only status/result;
- provider tests покрывают auto priority и GitHub API error mapping;
- workflow проверяется `actionlint`;
- Laravel tests, formatter и frontend build должны пройти до merge в `main`.

## Откат

Вернуть `PLAYER_CHARACTER_AI_PROVIDER=yandex`. Код и тесты Yandex не удаляются.
