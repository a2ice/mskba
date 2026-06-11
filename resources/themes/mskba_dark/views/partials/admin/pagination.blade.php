@if($paginator->hasPages())
    <div class="admin-pagination">
        <div class="admin-muted">
            Показано {{ $paginator->firstItem() }}-{{ $paginator->lastItem() }} из {{ $paginator->total() }}
        </div>
        <div class="d-flex gap-2">
            @if($paginator->onFirstPage())
                <span class="btn btn--secondary btn--sm disabled">Назад</span>
            @else
                <a href="{{ $paginator->previousPageUrl() }}" class="btn btn--secondary btn--sm">Назад</a>
            @endif

            @if($paginator->hasMorePages())
                <a href="{{ $paginator->nextPageUrl() }}" class="btn btn--secondary btn--sm">Далее</a>
            @else
                <span class="btn btn--secondary btn--sm disabled">Далее</span>
            @endif
        </div>
    </div>
@endif
