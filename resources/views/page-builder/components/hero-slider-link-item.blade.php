<a href="{{ $link['url'] }}" style="--highlight-index:{{ $index }}" @if ($duplicate) tabindex="-1" @endif>
    <span class="mskba-hero-slide__icon" aria-hidden="true">
        @if (! empty($link['icon_url']))
            <img class="mskba-hero-slide__custom-icon" src="{{ $link['icon_url'] }}" alt="" loading="lazy" decoding="async">
        @else
            @include('page-builder.components.hero-slider-icon', ['icon' => $link['icon'] ?? 'court'])
        @endif
    </span>
    <span>{{ $link['label'] }}</span>
</a>
