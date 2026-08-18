# Implementation

`routes/feed.php` загружается после основного `routes/web.php`. Существующие route names `news.index`/`news.show` сначала переименовываются в `legacy.news.*`, после чего новые canonical `/feed` routes получают исходные имена `news.*`. Это позволяет не менять существующие вызовы `route('news.*')`: они сразу генерируют новые URL.

`NewsController` различает legacy routes по имени и возвращает 301 на canonical route. Для статьи проверка публикации выполняется до редиректа, поэтому неопубликованный материал по старому URL остаётся 404.
