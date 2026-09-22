# 233 — Production-диагностика OpenAI

## Причина

После ротации `OPENAI_API_KEY` production продолжает получать 403, при этом OpenAI Dashboard
не показывает usage для нового project key. Нужно точно определить, видит ли production
актуальный key и какой именно OpenAI endpoint/model возвращает отказ.

## Реализовано

- добавлена команда `php artisan ai:openai:smoke`;
- команда не выводит API key и не пишет изображения;
- безопасно проверяет:
  - наличие `gpt-5.6-luna` через Models API;
  - наличие `gpt-image-2` через Models API;
  - минимальный Responses API вызов через `gpt-5.6-luna`;
- выводит только HTTP status, provider request id, error code/type/message;
- deploy запускает smoke diagnostic после `config:cache`;
- diagnostic не блокирует production deploy: сбой OpenAI выводится в Actions log, но сайт продолжает выкатываться.

После определения причины диагностическая команда может остаться как безопасный production smoke tool.
