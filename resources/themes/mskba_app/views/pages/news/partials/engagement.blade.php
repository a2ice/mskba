@php
    $compact = $compact ?? true;
    $plural = static function (int $number, array $words): string {
        $hundred = $number % 100;
        $ten = $number % 10;
        return $hundred >= 11 && $hundred <= 14 ? $words[2] : ($ten === 1 ? $words[0] : ($ten >= 2 && $ten <= 4 ? $words[1] : $words[2]));
    };
@endphp
<div class="app-news-engagement" data-news-engagement="{{ $contentItem->id }}" data-news-compact="{{ $compact ? '1' : '0' }}">
    <span class="app-news-engagement__item" title="Просмотры">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
        <span data-news-count="views">{{ $stats['views'] }}</span><span class="{{ $compact ? 'sr-only' : '' }}" data-news-word="views">{{ $plural($stats['views'], ['просмотр', 'просмотра', 'просмотров']) }}</span>
    </span>
    <button class="app-news-engagement__item" type="button" data-favorite-type="content" data-favorite-id="{{ $contentItem->id }}" data-favorite-name="{{ $contentItem->title }}" aria-pressed="{{ $stats['liked'] ? 'true' : 'false' }}" title="{{ $stats['liked'] ? 'Убрать из избранного' : 'Добавить в избранное' }}" aria-label="{{ $stats['liked'] ? 'Убрать из избранного' : 'Добавить в избранное' }}">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.7l-1.1-1.1a5.5 5.5 0 0 0-7.8 7.8L12 21l8.8-8.6a5.5 5.5 0 0 0 0-7.8Z"/></svg>
        <span data-news-count="likes">{{ $stats['likes'] }}</span><span class="{{ $compact ? 'sr-only' : '' }}" data-news-word="likes">{{ $plural($stats['likes'], ['лайк', 'лайка', 'лайков']) }}</span>
    </button>
    <a class="app-news-engagement__item" href="{{ $compact ? route('news.show', $contentItem->alias).'#news-comments' : '#news-comments' }}" title="Комментарии">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M15 16h3l4 4V8a2 2 0 0 0-2-2h-3M2 4a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H7l-5 4Z"/></svg>
        <span data-news-count="comments">{{ $stats['comments'] }}</span><span class="{{ $compact ? 'sr-only' : '' }}" data-news-word="comments">{{ $plural($stats['comments'], ['комментарий', 'комментария', 'комментариев']) }}</span>
    </a>
</div>
