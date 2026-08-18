# 096 - Перенос публичной ленты с /news на /feed

Публичная лента материалов переносится с `/news` на `/feed` без изменения существующих внутренних route names `news.index` и `news.show`.

- `route('news.index')` генерирует `/feed`;
- `route('news.show', $alias)` генерирует `/feed/{alias}`;
- старые `/news` и `/news/{alias}` остаются legacy routes и отвечают HTTP 301 на соответствующий `/feed` URL;
- query string при редиректе сохраняется;
- canonical URL материалов указывает непосредственно на `/feed/{alias}`;
- неопубликованный материал остаётся 404 и по legacy URL;
- старые redirects можно сохранять постоянно для внешних ссылок и переноса поисковых сигналов.

Реализация вынесена в `routes/feed.php`, который загружается после основных web routes. Он заменяет ранее зарегистрированные `/news` entries на `legacy.news.*` и регистрирует `/feed` под исходными `news.*` names, поэтому существующие внутренние ссылки сразу начинают вести на canonical URL без промежуточного redirect hop.

Проверено отдельным feature gate: `php artisan route:cache`, `FeedUrlTest` и существующий `ContentWorkflowTest` проходят успешно. Временный CI workflow после проверки удалён из итогового diff.
