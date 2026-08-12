@if($paginator->hasPages())
    <nav class="site-pagination" role="navigation" aria-label="Навигация по страницам">
        <p class="site-pagination__summary">
            Показано {{ $paginator->firstItem() }}–{{ $paginator->lastItem() }} из {{ $paginator->total() }}
        </p>

        <div class="site-pagination__pages">
            @if($paginator->onFirstPage())
                <span class="site-pagination__control is-disabled" aria-disabled="true">Назад</span>
            @else
                <a class="site-pagination__control" href="{{ $paginator->previousPageUrl() }}" rel="prev">Назад</a>
            @endif

            @foreach($elements as $element)
                @if(is_string($element))
                    <span class="site-pagination__ellipsis" aria-hidden="true">{{ $element }}</span>
                @endif

                @if(is_array($element))
                    @foreach($element as $page => $url)
                        @if($page === $paginator->currentPage())
                            <span class="site-pagination__page is-current" aria-current="page">{{ $page }}</span>
                        @else
                            <a class="site-pagination__page" href="{{ $url }}" aria-label="Страница {{ $page }}">{{ $page }}</a>
                        @endif
                    @endforeach
                @endif
            @endforeach

            @if($paginator->hasMorePages())
                <a class="site-pagination__control" href="{{ $paginator->nextPageUrl() }}" rel="next">Далее</a>
            @else
                <span class="site-pagination__control is-disabled" aria-disabled="true">Далее</span>
            @endif
        </div>
    </nav>
@endif
