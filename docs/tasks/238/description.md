# 238 — совместимость Yandex Responses для проверки лица

## Причина

Production успешно авторизуется в Yandex AI Studio и smoke получает HTTP 200, но реальная
проверка лица завершалась ошибкой «Яндекс AI не вернул результат проверки лица».

Документация Yandex показывает два допустимых представления ответа Responses API:
top-level `output_text` и message content через `output[].content[].text`. В реальном
wire response агрегированное `output_text` может отсутствовать.

## Исправление

- parser сначала использует top-level `output_text`;
- затем canonical `content.type=output_text`;
- поддерживает Yandex wire variant `content.type=text` и content без type;
- добавлен defensive chat-compatible fallback;
- при полном отсутствии текста логируются только безопасные метаданные формы ответа
  (status/keys/output types/content types/request id), без face images и prompt;
- добавлен regression test для реального Yandex wire variant;
- автоматический smoke убран из каждого deploy после подтверждения auth=200;
  ручная команда `ai:yandex:smoke` остаётся доступной.
