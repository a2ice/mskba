@php $title = 'Новости'; @endphp

@extends('theme::layouts.section-sidebar', [
    'title' => $title,
    'sectionId' => 'news',
    'sectionClass' => 'news-section',
    'contentTitle' => 'Новости',
    'contentSubtitle' => 'Материалы о баскетболе, площадках и жизни сообщества.',
    'sidebarLabel' => 'Навигация новостей',
])

@section('section-sidebar')
    <div class="section-sidebar-block">
        <h2 class="section-sidebar-block__title">Новости</h2>
        <ul class="sidebar-nav nav flex-column">
            <li class="nav-item active"><a class="nav-link active" href="{{ route('news.index') }}">Все материалы</a></li>
        </ul>
    </div>
@endsection

@section('section-content')
    @if($contentItems->isEmpty())
        <div class="alert alert-info">Материалов пока нет.</div>
    @else
        <div class="news-grid">
            @foreach($contentItems as $contentItem)
                @php $cover = $contentItem->cover->first(); @endphp
                <article class="news-card">
                    <a class="news-card__media" href="{{ route('news.show', $contentItem->alias) }}" @if(! $cover) aria-label="{{ $contentItem->title }}" @endif>
                        @if($cover)
                            <img src="{{ $cover->publicUrl() }}" alt="" loading="lazy">
                        @else
                            <span class="news-card__placeholder" aria-hidden="true"><i class="ti ti-basketball"></i></span>
                        @endif
                    </a>
                    <div class="news-card__body">
                        <div class="news-card__meta">
                            <span class="badge">{{ $contentItem->type->label() }}</span>
                            <time datetime="{{ $contentItem->feed_published_at->toIso8601String() }}">{{ $contentItem->feed_published_at->translatedFormat('d F Y') }}</time>
                        </div>
                        <h2 class="news-card__title"><a href="{{ route('news.show', $contentItem->alias) }}">{{ $contentItem->title }}</a></h2>
                        <p class="news-card__description">{{ $contentItem->short_description }}</p>
                        <a class="btn btn--secondary btn--sm" href="{{ route('news.show', $contentItem->alias) }}">Подробнее</a>
                    </div>
                </article>
            @endforeach
        </div>
        <div class="mt-4">{{ $contentItems->links() }}</div>
    @endif
@endsection
