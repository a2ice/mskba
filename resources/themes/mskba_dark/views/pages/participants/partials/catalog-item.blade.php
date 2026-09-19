@php($mode = $mode ?? 'card')

<article @class(['participant-category-item', 'participant-category-item--'.$mode])>
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

    <div class="participant-category-item__body">
        <div class="participant-category-item__roles">
            @foreach($item['roles'] as $role)
                <span class="catalog-card__badge">{{ $role['label'] }}</span>
            @endforeach
        </div>

        <h2 class="participant-category-item__title">
            <a href="{{ $item['url'] }}">{{ $item['name'] }}</a>
        </h2>

        @if($item['nickname'])
            <p class="participant-category-item__nickname">{{ '@'.$item['nickname'] }}</p>
        @endif
    </div>

    <a class="btn btn--secondary btn--sm participant-category-item__details" href="{{ $item['url'] }}">
        <span>Профиль</span><i class="ti ti-arrow-right" aria-hidden="true"></i>
    </a>
</article>
