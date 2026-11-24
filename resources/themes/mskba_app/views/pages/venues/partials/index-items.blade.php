@forelse($venues as $venue)
    @include('theme::pages.venues.partials.index-card', ['venue' => $venue])
@empty
    @if(($page ?? 1) === 1)
        <div class="app-venues-catalog__empty panel">
            <h2>Площадки не найдены</h2>
            <p>Измените фильтры или попробуйте другой запрос.</p>
            <a class="button secondary" href="{{ route('venues') }}">Сбросить фильтры</a>
        </div>
    @endif
@endforelse
