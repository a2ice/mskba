@php($mode = $mode ?? 'card')

<article @class(['participant-category-item', 'participant-category-item--'.$mode])>
    @if($item['url'])
        <a
            class="participant-category-item__avatar"
            href="{{ $item['url'] }}"
            @if($item['avatar_restricted']) title="Отображение аватара запрещено в настройках профиля" data-tooltip-variant="title" @endif
            aria-label="Профиль {{ $item['name'] }}"
        >
            @if($item['avatar_url'])
                <img src="{{ $item['avatar_url'] }}" alt="">
            @else
                <i class="ti ti-user" aria-hidden="true"></i>
            @endif
        </a>
    @else
        <div
            class="participant-category-item__avatar"
            @if($item['avatar_restricted']) title="Отображение аватара запрещено в настройках профиля" data-tooltip-variant="title" @endif
            aria-hidden="true"
        >
            @if($item['avatar_url'])
                <img src="{{ $item['avatar_url'] }}" alt="">
            @else
                <i class="ti ti-user" aria-hidden="true"></i>
            @endif
        </div>
    @endif

    <div class="participant-category-item__body">
        <div class="participant-category-item__roles">
            @if($item['is_own'] ?? false)
                <span class="catalog-card__badge participant-category-item__own-badge">Мой профиль</span>
            @endif
            @forelse($item['roles'] as $role)
                <span class="catalog-card__badge">{{ $role['label'] }}</span>
            @empty
                <span class="catalog-card__badge participant-category-item__participant-badge">Участник</span>
            @endforelse
        </div>

        <h2 class="participant-category-item__title">
            @if($item['url'])
                <a href="{{ $item['url'] }}">{{ $item['name'] }}</a>
            @else
                <span>{{ $item['name'] }}</span>
            @endif
        </h2>

        @if($item['nickname'])
            <p class="participant-category-item__nickname">{{ '@'.$item['nickname'] }}</p>
        @endif
    </div>

    @if($item['url'])
        <a class="btn btn--secondary btn--sm participant-category-item__details" href="{{ $item['url'] }}">
            <span>Профиль</span><i class="ti ti-arrow-right" aria-hidden="true"></i>
        </a>
    @else
        <span class="btn btn--secondary btn--sm participant-category-item__details participant-category-item__details--disabled" aria-disabled="true">
            <span>Профиль закрыт</span><i class="ti ti-lock" aria-hidden="true"></i>
        </span>
    @endif
</article>
