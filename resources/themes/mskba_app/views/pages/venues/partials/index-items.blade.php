@forelse($venues as $venue)
    @php
        $url = $venue->matchedCourtUrl ?: route('venues.show', $venue->routeIdentifier());
        $free = $venue->courtAccessSummary['free'] ?? [];
        $paid = $venue->courtAccessSummary['paid'] ?? [];
        $approval = $venue->courtAccessSummary['approval'] ?? [];
    @endphp
    <article class="app-venues-catalog__card panel app-venue-card" data-venue-id="{{ $venue->id }}">
        <a class="app-venues-catalog__photo" href="{{ $url }}">
            <img src="{{ $venue->imageUrl ?: asset('images/venue-placeholder.png') }}" alt="Площадка {{ $venue->name }}" loading="lazy">
        </a>
        <button class="mskba-favorite-heart mskba-favorite-heart--card" type="button"
            data-favorite-venue="{{ $venue->id }}" aria-label="Добавить в избранное"
            title="Добавить в избранное" aria-pressed="false">
            <svg aria-hidden="true"><use href="#heart"/></svg>
        </button>
        <div class="app-venues-catalog__card-body">
            <div class="app-venues-catalog__badges">
                <span>{{ $venue->type }}</span><span>{{ $venue->operationalStatus }}</span>
            </div>
            <h2><a href="{{ $url }}">{{ $venue->name }}</a></h2>
            @if($venue->displayAddress)
                <p class="app-venues-catalog__address">{{ $venue->displayAddress }}</p>
            @endif
            @if($venue->shortDescription)
                <p class="app-venues-catalog__description">{{ $venue->shortDescription }}</p>
            @endif
            <p class="app-venues-catalog__access">
                @if($free !== []){{ $paid !== [] ? 'Есть бесплатный доступ' : 'Бесплатно' }}
                @elseif($paid !== [])Платно
                @elseif($approval !== [])Требуется подтверждение
                @else Условия уточняются
                @endif
            </p>
            <a class="button secondary app-venues-catalog__details" href="{{ $url }}">Подробнее <span aria-hidden="true">→</span></a>
        </div>
    </article>
@empty
    @if(($page ?? 1) === 1)
        <div class="app-venues-catalog__empty panel">
            <h2>Площадки не найдены</h2>
            <p>Измените фильтры или попробуйте другой запрос.</p>
            <a class="button secondary" href="{{ route('venues') }}">Сбросить фильтры</a>
        </div>
    @endif
@endforelse
