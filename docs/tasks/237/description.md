# 237 — восстановление production deploy после добавления Yandex smoke

Task 236 повредил YAML workflow при программной вставке shell-условия в deploy script.

Исправление:
- восстановлен последний рабочий production workflow из Task 235;
- безопасный Yandex smoke добавлен после `config:cache`;
- smoke остаётся non-blocking и запускается только при активном Yandex provider.
