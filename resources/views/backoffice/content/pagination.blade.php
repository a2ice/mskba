@if ($paginator->hasPages())
    <nav class="content-admin-actions" aria-label="Страницы списка">
        @if ($paginator->previousPageUrl())
            <x-filament::button tag="a" color="gray" :href="$paginator->previousPageUrl()" rel="prev">Назад</x-filament::button>
        @endif
        <span>{{ $paginator->currentPage() }} / {{ $paginator->lastPage() }}</span>
        @if ($paginator->nextPageUrl())
            <x-filament::button tag="a" color="gray" :href="$paginator->nextPageUrl()" rel="next">Далее</x-filament::button>
        @endif
    </nav>
@endif
