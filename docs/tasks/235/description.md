# 235 — Yandex AI Studio для генератора персонажа

## Цель

Подключить Yandex AI Studio как основной production-compatible provider для генерации
2D-персонажа игрока, сохранив существующий `PlayerCharacterAiGateway`.

## Реализовано

- добавлен `YandexPlayerCharacterAiGateway`;
- face validation идёт через Responses API и мультимодальную Qwen 3.6;
- генерация идёт через Responses API + `image_generation` / Alice AI ART;
- face references передаются как image inputs;
- включён `input_fidelity=high` для максимально точного сохранения лица;
- provider выбирается через `PLAYER_CHARACTER_AI_PROVIDER=auto|yandex|openai|null`;
- `auto` предпочитает Yandex, затем OpenAI;
- production deploy умеет синхронизировать `YANDEX_AI_API_KEY` и
  `YANDEX_AI_FOLDER_ID` из GitHub Actions secrets;
- OpenAI gateway остаётся как fallback/override;
- добавлены provider-selection и gateway tests.

## Ограничение первой итерации

Документация Yandex помечает параметр прозрачного background в Image Generation Tool как
currently unsupported. MSKBA не принимает opaque PNG как финальный результат и возвращает
`generation_background_not_transparent`.

После первого production smoke нужно проверить фактический результат Alice AI ART. Если фон
стабильно непрозрачный, следующим шагом станет безопасный локальный background-removal этап,
а не ослабление требования прозрачного PNG.
