<p class="app-news-meta">
    <span>{{ $contentItem->type->label() }}</span>
    @if($contentItem->feed_published_at)
        <span aria-hidden="true">·</span>
        <time datetime="{{ $contentItem->feed_published_at->toIso8601String() }}">{{ $contentItem->feed_published_at->locale('ru')->translatedFormat('j F Y') }}</time>
    @endif
</p>
