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
- opaque image теперь считается успешным MVP-результатом;
- MIME определяется по фактическим bytes, а не жёстко задаётся как PNG;
- логируются actual MIME/dimensions, image call count и request metadata;
- тест закрепляет first-result-wins и отсутствие требования alpha;
- если POST возвращает `queued|in_progress`, gateway опрашивает
  `GET /v1/responses/{id}` до `completed` либо до общего generation timeout;
- polling повторно не создаёт image-generation request и поэтому не запускает новую генерацию.

## Следующий этап

После подтверждения production flow добавить background removal/chroma key, нормализацию
в прозрачный PNG и отдельно решить риск лишних provider tool calls/стоимости.
