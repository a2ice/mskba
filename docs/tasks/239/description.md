# 239 — production-диагностика multimodal structured response Yandex

После Task 238 production по-прежнему сообщает, что Yandex AI не вернул результат проверки лица,
хотя обычный Responses smoke получает HTTP 200.

Добавлено:
- `ai:yandex:smoke --multimodal`;
- synthetic PNG без пользовательских данных;
- запрос повторяет реальную связку image input + strict json_schema;
- в Actions log выводится только безопасная форма ответа: status, top-level keys,
  output/output_text/content types, incomplete details и provider error;
- production deploy временно запускает этот smoke один раз для точной диагностики.
