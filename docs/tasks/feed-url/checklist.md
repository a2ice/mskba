# Проверка

- `/feed` отвечает 200;
- `/feed/{alias}` отвечает 200 для опубликованного материала;
- `/news` отвечает 301 на `/feed`;
- `/news/{alias}` отвечает 301 на `/feed/{alias}`;
- query string сохраняется;
- canonical URL статьи использует `/feed/{alias}`;
- `php artisan route:cache` выполняется без конфликтов имён маршрутов.
