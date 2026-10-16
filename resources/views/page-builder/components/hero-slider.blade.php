<section class="mskba-hero-slider"
    data-mskba-hero-slider
    data-autoplay="{{ $options['autoplay'] ? '1' : '0' }}"
    data-interval="{{ $options['interval_ms'] }}"
    aria-label="Главные разделы MSKBA"
    aria-roledescription="карусель"
    tabindex="0">
    <div class="mskba-hero-slider__stage">
        @foreach ($slides as $slide)
            <article class="mskba-hero-slide {{ $loop->first ? 'is-active' : '' }}"
                data-hero-slide data-slide-index="{{ $loop->index }}"
                aria-label="Слайд {{ $loop->iteration }} из {{ count($slides) }}"
                aria-roledescription="слайд"
                @unless($loop->first) hidden inert aria-hidden="true" @endunless
                style="--far-x:{{ $slide['far_x'] }}%;--near-x:{{ $slide['near_x'] }}%;--far-width:{{ $slide['far_width'] }}%;--near-width:{{ $slide['near_width'] }}%;--far-mobile-x:{{ $slide['far_mobile_x'] }}%;--near-mobile-x:{{ $slide['near_mobile_x'] }}%">
                @if ($slide['background_desktop_url'])
                    <picture class="mskba-hero-slide__background" aria-hidden="true">
                        @if ($slide['background_mobile_url'])
                            <source media="(max-width: 640px)" srcset="{{ $slide['background_mobile_url'] }}">
                        @endif
                        <img src="{{ $slide['background_desktop_url'] }}" alt="" decoding="async"
                             @if ($loop->first) fetchpriority="high" @else loading="lazy" @endif>
                    </picture>
                @else
                    <div class="mskba-hero-slide__background mskba-hero-slide__background--placeholder" aria-hidden="true"></div>
                @endif
                <div class="mskba-hero-slide__scrim" aria-hidden="true"></div>

                @if ($slide['far_player_url'])
                    <img class="mskba-hero-slide__player mskba-hero-slide__player--far"
                        data-motion="{{ $slide['animation_far'] ?? 'slide-up' }}"
                        src="{{ $slide['far_player_url'] }}" alt="" aria-hidden="true"
                        loading="{{ $loop->first ? 'eager' : 'lazy' }}" decoding="async">
                @endif
                @if ($slide['near_player_url'])
                    <img class="mskba-hero-slide__player mskba-hero-slide__player--near"
                        data-motion="{{ $slide['animation_near'] ?? 'zoom' }}"
                        src="{{ $slide['near_player_url'] }}" alt="" aria-hidden="true"
                        loading="{{ $loop->first ? 'eager' : 'lazy' }}" decoding="async">
                @endif

                <div class="mskba-hero-slide__copy" data-motion="{{ $slide['animation_copy'] ?? 'slide-up' }}">
                    @if (! empty($slide['eyebrow']))
                        <p class="mskba-hero-slide__eyebrow">{{ $slide['eyebrow'] }}</p>
                    @endif
                    @if ($loop->first)
                        <h1 class="mskba-hero-slide__title">{!! nl2br(e($slide['title'])) !!}
                            @if (! empty($slide['accent_title']))<span>{{ $slide['accent_title'] }}</span>@endif
                        </h1>
                    @else
                        <h2 class="mskba-hero-slide__title">{!! nl2br(e($slide['title'])) !!}
                            @if (! empty($slide['accent_title']))<span>{{ $slide['accent_title'] }}</span>@endif
                        </h2>
                    @endif
                    @if (! empty($slide['description']))
                        <p class="mskba-hero-slide__description">{{ $slide['description'] }}</p>
                    @endif
                    <a class="mskba-hero-slide__cta" href="{{ $slide['cta_url'] }}">
                        {{ $slide['cta_label'] }} <span aria-hidden="true">→</span>
                    </a>
                </div>

                @if (! empty($slide['highlights']))
                    <nav class="mskba-hero-slide__highlights" aria-label="Разделы портала">
                        @foreach ($slide['highlights'] as $highlight)
                            <a href="{{ $highlight['url'] }}" style="--highlight-index:{{ $loop->index }}">
                                <span class="mskba-hero-slide__icon" aria-hidden="true">
                                    <svg viewBox="0 0 32 32" fill="none" stroke="currentColor"
                                         stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
                                        @switch($highlight['icon'])
                                            @case('court')
                                                <rect x="3.5" y="4.5" width="25" height="23" rx="2"/>
                                                <path d="M16 5v22M4 16h24M4 11c5 1 7 3 7 5s-2 4-7 5m24-10c-5 1-7 3-7 5s2 4 7 5"/>
                                                @break
                                            @case('trophy')
                                                <path d="M9 5h14v7a7 7 0 0 1-14 0V5Zm14 3h5v2c0 4-2 6-6 7M9 8H4v2c0 4 2 6 6 7M16 19v6m-6 3h12m-11-3h10"/>
                                                @break
                                            @case('users')
                                                <circle cx="12" cy="11" r="4"/><circle cx="23" cy="12" r="3"/>
                                                <path d="M3.5 27v-3c0-5 3.2-7.5 8.5-7.5s8.5 2.5 8.5 7.5v3H3.5Zm17-9.5c5.2 0 8 2.4 8 7V27h-5"/>
                                                @break
                                            @case('video')
                                                <rect x="3.5" y="5" width="25" height="22" rx="3"/><path d="m13 11 9 5-9 5V11Z"/>
                                                @break
                                            @case('chart')
                                                <rect x="3.5" y="19" width="5" height="9" rx=".6"/><rect x="13.5" y="13" width="5" height="15" rx=".6"/><rect x="23.5" y="5" width="5" height="23" rx=".6"/>
                                                @break
                                        @endswitch
                                    </svg>
                                </span>
                                <span>{{ $highlight['label'] }}</span>
                            </a>
                        @endforeach
                    </nav>
                @endif
            </article>
        @endforeach
    </div>

    @if (count($slides) > 1)
        @if ($options['show_arrows'])
            <button type="button" class="mskba-hero-slider__arrow mskba-hero-slider__arrow--prev"
                data-hero-prev aria-label="Предыдущий слайд">‹</button>
            <button type="button" class="mskba-hero-slider__arrow mskba-hero-slider__arrow--next"
                data-hero-next aria-label="Следующий слайд">›</button>
        @endif
        @if ($options['show_dots'])
            <div class="mskba-hero-slider__pagination" role="group" aria-label="Выбор слайда">
                @foreach ($slides as $slide)
                    <button type="button" data-hero-go="{{ $loop->index }}"
                        aria-label="Перейти к слайду {{ $loop->iteration }}"
                        @if ($loop->first) aria-current="true" @endif></button>
                @endforeach
            </div>
        @endif
    @endif
</section>
