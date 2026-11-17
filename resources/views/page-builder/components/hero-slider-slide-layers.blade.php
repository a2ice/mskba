{{-- Typed, server-rendered, escaped elements; user data never becomes HTML/JS. --}}
@if ($slide['background_desktop_url'])
    <picture class="mskba-hero-slide__background" aria-hidden="true"
        data-motion="{{ $slide['background_effect'] ?? 'fade' }}">
        @if ($slide['background_mobile_url'])
            <source media="(max-width: 640px)" srcset="{{ $slide['background_mobile_url'] }}">
        @endif
        <img src="{{ $slide['background_desktop_url'] }}" alt="" decoding="async"
            @if (! empty($slide['background_desktop_lqip_url'])) data-site-media-lqip="{{ $slide['background_desktop_lqip_url'] }}" @endif
            @if (! empty($slide['background_mobile_lqip_url'])) data-site-media-lqip-mobile="{{ $slide['background_mobile_lqip_url'] }}" @endif
            @if ($firstVisible) fetchpriority="high" @else loading="lazy" @endif>
    </picture>
@endif
@if (($slide['scrim'] ?? 'none') !== 'none')
    <div class="mskba-hero-slide__layer-scrim mskba-hero-slide__layer-scrim--{{ $slide['scrim'] }}" aria-hidden="true"></div>
@endif
@foreach ($slide['elements'] as $element)
    @php
        $kind = $element['type'];
        $class = trim((string) ($element['class_name'] ?? ''));
        $style = '--layer-x:'.$element['x'].'%;--layer-y:'.$element['y'].'%;--layer-width:'.$element['width'].'%;'
            .'--layer-tablet-x:'.$element['tablet_x'].'%;--layer-tablet-y:'.$element['tablet_y'].'%;'
            .'--layer-tablet-width:'.$element['tablet_width'].'%;'
            .'--layer-mobile-x:'.$element['mobile_x'].'%;--layer-mobile-y:'.$element['mobile_y'].'%;'
            .'--layer-mobile-width:'.$element['mobile_width'].'%;--layer-z:'.$element['z_index'].';'
            .'--layer-mobile-gap:'.$element['mobile_gap'].'px;'
            .'--layer-delay:'.$element['delay_ms'].'ms;--layer-duration:'.$element['duration_ms'].'ms;';
        $motion = $element['animation'] ?? 'fade';
    @endphp
    <div class="mskba-hero-slide__layer mskba-hero-slide__layer--{{ $kind }} {{ $class }}"
        data-motion="{{ $motion }}" data-layer-name="{{ $element['name'] }}"
        data-mobile-placement="{{ $element['mobile_placement'] }}"
        style="{{ $style }}text-align:{{ $element['align'] ?? 'left' }};">
        @switch($kind)
            @case('heading')
                @if ($firstVisible)
                    <h1 class="mskba-hero-slide__layer-heading">{!! nl2br(e($element['content'])) !!}</h1>
                @else
                    <h2 class="mskba-hero-slide__layer-heading">{!! nl2br(e($element['content'])) !!}</h2>
                @endif
                @break
            @case('text')
                <p class="mskba-hero-slide__layer-text">{!! nl2br(e($element['content'])) !!}</p>
                @break
            @case('image')
                @if ($element['image_url'])
                    <img class="mskba-hero-slide__layer-image" src="{{ $element['image_url'] }}"
                        @if (! empty($element['image_lqip_url'])) data-site-media-lqip="{{ $element['image_lqip_url'] }}" @endif
                        alt="{{ $element['alt'] ?? '' }}" decoding="async" loading="{{ $firstVisible ? 'eager' : 'lazy' }}">
                @endif
                @break
            @case('button')
                <a class="mskba-hero-slide__layer-button" href="{{ $element['url'] }}">{{ $element['content'] }}@if ($element['show_arrow'] ?? true)<span aria-hidden="true">→</span>@endif</a>
                @break
            @case('links')
                @include('page-builder.components.hero-slider-links', ['element' => $element])
                @break
        @endswitch
    </div>
@endforeach
