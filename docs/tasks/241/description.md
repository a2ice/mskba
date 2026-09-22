# 241 — минимальный рабочий Yandex image result

## Цель

Подтвердить end-to-end генерацию персонажа на production и показать пользователю первое
реально сгенерированное изображение, даже если Yandex пока возвращает непрозрачный фон.

## Production evidence

Реальный Responses вызов успешно завершил image generation, но рабочий tool contract отличается
от первоначальной интеграции:

- явный `model=aliceai-image-art-3.0` отклоняется;
- явный `output_format=png` отклоняется;
- `action=auto` отклоняется, рабочий вариант — `action=generate`;
- Yandex может вернуть несколько `image_generation_call` даже при `max_tool_calls=1`;
- фактическое изображение может быть opaque и иметь размер, отличный от requested size;
- production также возвращает HTTP 200 с top-level `status=in_progress`, `output=[]` и
  валидным response `id`; это не ошибка генерации, а промежуточное состояние Responses API.

## Изменения

- image tool больше не передаёт explicit model/output_format;
- используется `action=generate`;
- prompt просит один full-body result на ровном зелёном `#00FF00` фоне;
- gateway берёт первый completed image call и игнорирует последующие;
- opaque provider image остаётся допустимым входом post-processing;
- только в Yandex provider включён локальный green-screen cleanup: edge-connected chroma pixels
  удаляются через flood-fill mask, после чего результат сохраняется как transparent PNG;
- зелёные детали, не соединённые с краями изображения, сохраняются;
- cleanup fail-open: при ошибке или отсутствии подходящего фона пользователь всё равно получает
  исходный валидный provider result;
- MIME определяется по фактическим bytes до post-processing; после успешного cleanup итог — PNG;
- логируются actual MIME/dimensions, background_removed, image call count и request metadata;
- тест закрепляет first-result-wins и отсутствие требования alpha;
- если POST возвращает `queued|in_progress`, gateway опрашивает
  `GET /v1/responses/{id}` до `completed` либо до общего generation timeout;
- polling повторно не создаёт image-generation request и поэтому не запускает новую генерацию.

## Следующий этап

На production проверить качество краёв на реальных генерациях и при необходимости добавить
мягкий despill/feathering. Отдельно остаётся риск лишних provider tool calls/стоимости.
Yandex-only cleanup можно отключить через YANDEX_AI_REMOVE_GREEN_BACKGROUND без влияния на OpenAI.
