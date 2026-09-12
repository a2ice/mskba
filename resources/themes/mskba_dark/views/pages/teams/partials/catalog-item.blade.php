@php
    $mode = $mode ?? 'card';
@endphp

<article @class(['team-category-item', 'team-category-item--'.$mode])>
    <a class="team-category-item__image" href="{{ $item['url'] }}" aria-label="{{ $item['name'] }}">
        <img src="{{ $item['logo_url'] }}" alt="Логотип команды {{ $item['name'] }}">
    </a>

    <div class="team-category-item__body">
        <div class="catalog-card__badges team-category-item__badges">
            <span class="catalog-card__badge team-status-badge" title="{{ $item['status']['label'] }}" data-tooltip-variant="title" aria-label="{{ $item['status']['label'] }}">
                <span class="team-status-badge__label">{{ $item['status']['label'] }}</span>
                <i class="ti {{ $item['status']['icon'] }} team-status-badge__icon" aria-hidden="true"></i>
            </span>

            @foreach($item['sports'] as $sport)
                <span class="catalog-card__badge is-sport" title="{{ $sport['label'] }}" data-tooltip-variant="title" aria-label="{{ $sport['label'] }}">
                    <span class="is-sport__full" aria-hidden="true">{{ $sport['label'] }}</span>
                    <span class="is-sport__short" aria-hidden="true">{{ $sport['short_label'] }}</span>
                </span>
            @endforeach

            @unless($item['roster_complete'])
                <span class="catalog-card__badge is-incomplete team-status-badge" title="Неполный состав" data-tooltip-variant="title" aria-label="Неполный состав">
                    <span class="team-status-badge__label">Неполный состав</span>
                    <i class="ti ti-alert-triangle team-status-badge__icon" aria-hidden="true"></i>
                </span>
            @endunless

            @if($item['hiring_count'] > 0)
                <span class="catalog-card__badge team-status-badge is-hiring" title="Команда ведёт набор" data-tooltip-variant="title" aria-label="Команда ведёт набор">
                    <span class="team-status-badge__label">Идёт набор</span>
                    <i class="ti ti-user-plus team-status-badge__icon" aria-hidden="true"></i>
                </span>
            @endif
        </div>

        <h2 class="team-category-item__title"><a href="{{ $item['url'] }}">{{ $item['name'] }}</a></h2>

        @if($mode === 'card')
            <p class="team-category-item__description">{{ $item['description'] }}</p>
        @endif

        <div class="team-category-item__meta">
            <p title="{{ $item['member_count_text'] }}" data-tooltip-variant="title" aria-label="{{ $item['member_count_text'] }}">
                <i class="ti ti-users" aria-hidden="true"></i><span>{{ $item['member_count_text'] }}</span>
            </p>
            <p @class(['is-missing' => $item['coach_name'] === '—']) title="Тренер: {{ $item['coach_name'] }}" data-tooltip-variant="title" aria-label="Тренер: {{ $item['coach_name'] }}">
                <i class="ti ti-user-cog" aria-hidden="true"></i><span>Тренер: {{ $item['coach_name'] }}</span>
            </p>
            <p @class(['is-missing' => $item['captain_name'] === '—']) title="Капитан: {{ $item['captain_name'] }}" data-tooltip-variant="title" aria-label="Капитан: {{ $item['captain_name'] }}">
                <i class="ti ti-star" aria-hidden="true"></i><span>Капитан: {{ $item['captain_name'] }}</span>
            </p>
        </div>
    </div>

    <a class="btn btn--secondary btn--sm team-category-item__details" href="{{ $item['url'] }}">
        <span>Подробнее</span><i class="ti ti-arrow-right" aria-hidden="true"></i>
    </a>
</article>
