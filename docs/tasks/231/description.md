# 231 — Реальный OpenAI provider для персонажа игрока

## Цель

Подключить реальный OpenAI API к уже существующему `PlayerCharacterAiGateway` без изменения
предметного контракта Identity.

## Реализовано

- добавлен `OpenAiPlayerCharacterAiGateway`;
- при наличии `OPENAI_API_KEY` контейнер автоматически использует OpenAI provider;
- без ключа сохраняется безопасный `NullPlayerCharacterAiGateway`;
- новые face references проверяются через Responses API одним batch-вызовом с image inputs
  и strict JSON Schema;
- генерация выполняется через Images Edit API с несколькими подтверждёнными face references;
- default validation model: `gpt-5.6-luna`;
- default image model: `gpt-image-2`;
- output: PNG 1024×1536, quality medium, `background=transparent`;
- после ответа backend дополнительно проверяет PNG и прозрачность углов; непрозрачный результат
  считается ошибкой генерации;
- ошибки auth/quota/rate-limit/timeout/5xx/4xx переводятся в стабильные MSKBA error codes;
- в логах сохраняются operation/model/provider request id, но не API key и не изображения;
- добавлены feature tests HTTP-контракта gateway.

## Production bootstrap ключа

Временный `config/tmp` был уже успешно задеплоен на production. Deploy Task 231 до
`git reset` один раз переносит его значение в server-only `.env` как `OPENAI_API_KEY`.
В новой ревизии `config/tmp` удалён из рабочего дерева.

После тестирования ключ должен быть ротирован, так как временное значение остаётся в git history.

## Prompt generation

Generated player:
- один человек;
- photorealistic;
- full body, голова и обе стопы полностью в кадре;
- front-facing neutral athletic pose;
- identity берётся из подтверждённых face references;
- team colors используются как палитра формы;
- кроссовки и спортивные атрибуты берутся из character payload;
- никакого текста, номеров, спонсоров и выдуманных логотипов;
- фон строго прозрачный, без пола, сцены и внешней тени.

## Финансы

Task 231 не меняет финансовый контур: Pricing/balance preflight уже выполняется до внешнего API.
Списание/hold/refund остаётся следующим этапом.
