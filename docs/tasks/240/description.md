# 240 — реальный face input Yandex Responses

## Наблюдение production

После deploy #804 synthetic multimodal structured smoke стабильно получает HTTP 200,
`status=completed` и `output[].content[].type=output_text`.

Реальные попытки пользователя в 20:14–20:16 проходили тот же HTTP endpoint, но ответ
имел `status=failed`, пустой `output` и непустой provider `error`. Значит проблема
уже не в parser и не в балансе MSKBA.

Ключевое отличие smoke от реального flow: smoke отправлял PNG, а face references после
внутренней нормализации MSKBA отправлялись как WebP.

## Исправление

- Yandex gateway перед отправкой перекодирует face references в PNG data URL;
- private storage остаётся WebP, менять существующий media contract не нужно;
- одинаковое PNG-представление используется и для face validation, и для identity
  references при generation;
- provider-level `status=failed` теперь логирует безопасные `error.code/type/message`;
- data-image payload в provider message редактируется перед логированием;
- известные image input errors маппятся в `face_reference_invalid_file`;
- regression tests проверяют, что входной WebP фактически уходит в Yandex как PNG.

После deploy нужно повторить одну реальную генерацию. Если Yandex снова вернёт
`status=failed`, production log уже покажет точный provider error без изображения и API key.
