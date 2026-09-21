# Task 225 — AI-контур персонажа игрока

## Контекст

Task 224 добавил единый 2D/3D stage, статичную 2D-заглушку и три face-reference slot.
Следующий этап нужен для правильной границы с платным image/vision API и для отработки
ошибок до подключения реального provider.

## Реализовано

- добавлен внутренний `App\Modules\Ai` и `PlayerCharacterAiGateway`;
- default provider — `NullPlayerCharacterAiGateway`, поэтому production безопасно
  отвечает `ai_not_configured` и не делает внешних запросов;
- загрузка лица больше не сохраняет файл до AI-проверки;
- техническая нормализация выполняется в памяти;
- подтверждённый reference сохраняется только после `valid=true`;
- старый подтверждённый reference сохраняется при любой ошибке замены;
- в UI учитываются только AI-confirmed references;
- добавлена кнопка «Сгенерировать 2D через AI»;
- генерация AJAX-ом сначала разрешает цену `avatar_generation` и проверяет total balance;
- при недостаточном балансе provider не вызывается;
- после баланса проверяется анфас + минимум один подтверждённый профиль;
- введены стабильные machine error codes;
- AI-ошибки логируются структурированно на backend;
- предыдущая картинка остаётся на stage во время spinner/error.

## Финансы

Текущая цена берётся из Pricing, а не зашита во frontend. На момент реализации каталога
`avatar_generation` стоит 100 ₽.

Task 225 не списывает средства: реальный provider не подключён. При включении provider
нужно завершить Purchase/hold/refund flow, после чего successful generation сможет стать
платной операцией `internal_service_payment`.

## Ошибки, которые можно проверить до подключения API

- нулевой/недостаточный баланс → `insufficient_balance`;
- отключённая цена → `pricing_unavailable`;
- нет подтверждённых face references → `face_references_missing`;
- загрузка фото при текущем null provider → `ai_not_configured`;
- генерация после полного preflight при null provider → `ai_not_configured`.

Контракт также заранее содержит connection/rate-limit/rejected/timeout/generation failure.

## Не входит

- реальный OpenAI provider и API key;
- списание/hold/refund;
- постоянное хранение generated 2D image;
- автоматическое применение skin tone из vision result;
- outfit prompt builder и принты;
- штатные male/female default illustrations.

## Проверки

Feature tests защищают отсутствие persistence до AI approval, preflight баланса,
наличие подтверждённых ракурсов и стабильные provider error codes.
