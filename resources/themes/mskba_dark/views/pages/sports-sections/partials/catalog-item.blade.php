@php($mode = $mode ?? 'card')

<article @class(['sports-section-category-item', 'sports-section-category-item--'.$mode])>
    <a class="sports-section-category-item__image" href="{{ $item['url'] }}" aria-label="{{ $item['name'] }}">
        <img src="{{ $item['image_url'] }}" alt="Фото секции {{ $item['name'] }}">
    </a>

    <div class="sports-section-category-item__body">
        <div class="catalog-card__badges sports-section-category-item__badges">
            <span class="catalog-card__badge">
                {{ $item['training_mode']['label'] }}
            </span>
            <span class="catalog-card__badge is-sport">
                {{ $item['format']['label'] }}
            </span>
            @if($item['recruitment'])
                <span @class([
                    'catalog-card__badge',
                    'sports-section-category-item__badge',
                    'sports-section-category-item__badge--recruiting' => $item['recruitment']['kind'] === 'recruiting',
                    'sports-section-category-item__badge--applications' => $item['recruitment']['kind'] === 'applications',
                    'sports-section-category-item__badge--full' => $item['recruitment']['kind'] === 'full',
                    'sports-section-recruitment-badge--pulse' => $item['recruitment']['kind'] === 'recruiting',
                ])>
                    {{ $item['recruitment']['label'] }}
                </span>
            @endif
        </div>

        <h2 class="sports-section-category-item__title"><a href="{{ $item['url'] }}">{{ $item['name'] }}</a></h2>

        @if($mode === 'card')
            <p class="sports-section-category-item__description">{{ $item['description'] }}</p>
        @endif

        <div class="sports-section-category-item__meta">
            <p @class(['is-missing' => $item['venue'] === null])>
                <i class="ti ti-map-pin" aria-hidden="true"></i>
                <span>{{ $item['venue']['name'] ?? 'Площадка не указана' }}</span>
            </p>
            <p @class(['is-missing' => count($item['teams']) === 0])>
                <i class="ti ti-users-group" aria-hidden="true"></i>
                <span class="sports-section-category-item__teams">
                    @if(count($item['teams']) > 0)
                        @foreach($item['teams'] as $team)
                            <a href="{{ $team['url'] }}">{{ $team['name'] }}</a>@if(! $loop->last)<span aria-hidden="true">, </span>@endif
                        @endforeach
                    @else
                        Команда не связана
                    @endif
                </span>
            </p>
            <p>
                <i class="ti ti-users" aria-hidden="true"></i><span>{{ $item['participant_count_text'] }}</span>
            </p>
            <p>
                <i class="ti ti-cash" aria-hidden="true"></i><span>{{ $item['pricing_text'] }}</span>
            </p>
        </div>
    </div>

    <a class="btn btn--secondary btn--sm sports-section-category-item__details" href="{{ $item['url'] }}">
        <span>Подробнее</span><i class="ti ti-arrow-right" aria-hidden="true"></i>
    </a>
</article>
