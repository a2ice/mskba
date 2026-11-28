<h2>Новости</h2>
@php($categoryLinkQuery = ($categoryView ?? 'list') === 'cards' ? ['view' => 'cards'] : [])
<nav class="app-category__nav" aria-label="Разделы новостей">
    <a href="{{ route('news.index', $categoryLinkQuery) }}" @class(['is-active' => !filled($filters['type'] ?? null)])>Все новости</a>
    @foreach($types as $contentType)
        <a href="{{ route('news.index', array_merge($categoryLinkQuery, ['type' => $contentType->value])) }}"
            @class(['is-active' => ($filters['type'] ?? '') === $contentType->value])>{{ $contentType->label() }}</a>
    @endforeach
</nav>
@include('theme::pages.news.partials.category-filters', ['scope' => $scope ?? 'desktop'])
