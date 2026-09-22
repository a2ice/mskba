# 232 — безопасная ротация OpenAI key и диагностика auth

## Причина

Первый тестовый `OPENAI_API_KEY` был временно закоммичен в публичный репозиторий.
OpenAI отключает обнаруженные публично опубликованные API keys, поэтому production получил
управляемую ошибку авторизации уже на первом face-validation request.

## Исправления

- production deploy больше не использует repository file для OpenAI credentials;
- источником истины становится GitHub Actions secret `OPENAI_API_KEY`;
- при каждом deploy, если secret задан, он синхронизируется в server-only `.env`;
- текущий image model default исправлен на `gpt-image-2`;
- 401, 403 и IP allowlist ошибки различаются пользовательскими error codes/messages;
- существующий Null provider остаётся fallback только когда ключ не настроен.

## Ротация

1. создать новый OpenAI project API key;
2. сохранить его в GitHub repository secret `OPENAI_API_KEY`;
3. deploy синхронизирует ключ в production `.env`;
4. старый опубликованный ключ удалить/оставить отключённым в OpenAI dashboard.

Новый ключ никогда не должен попадать в git, issue, PR body или application logs.
