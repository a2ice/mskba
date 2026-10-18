{{-- PR1: trusted, static scene layout on the published DEV homepage.
     Artwork and semantic content only; parallax / ball handoff arrive in PR2/PR3.
     The scenery deliberately belongs to the shared .mskba-home-story
     (it begins behind Hero, not at the top of the venues text block). --}}
<div class="mskba-venues-art" aria-hidden="true">
    <img class="mskba-venues-art__layer mskba-venues-art__layer--backstage"
         src="{{ asset('images/court_day/homepage/layer-1-backstage.png') }}"
         alt="" decoding="async" loading="eager">
    <img class="mskba-venues-art__layer mskba-venues-art__layer--fence"
         src="{{ asset('images/court_day/homepage/layer-2-fense.png') }}"
         alt="" decoding="async" loading="eager">
    <img class="mskba-venues-art__layer mskba-venues-art__layer--hoop"
         src="{{ asset('images/court_day/homepage/layer-3-hoop.png') }}"
         alt="" decoding="async" loading="eager">
    <img class="mskba-venues-art__layer mskba-venues-art__layer--player"
         src="{{ asset('images/court_day/homepage/layer-4-player.png') }}"
         alt="" decoding="async" loading="eager">
</div>

<section class="mskba-venues-scene" id="venues-scene"
         data-mskba-venues-scene aria-labelledby="mskba-venues-title">
    <div class="container mskba-venues-scene__container">
        <div class="mskba-venues-scene__copy">
            <span class="mskba-venues-scene__ghost" aria-hidden="true">ПЛОЩАДКИ</span>
            <p class="mskba-venues-scene__eyebrow">ИГРАЙ В ЛЮБОМ РАЙОНЕ</p>
            <h2 class="mskba-venues-scene__title" id="mskba-venues-title">ПЛОЩАДКИ</h2>
            <p class="mskba-venues-scene__description">
                Открытые и крытые площадки Москвы: карта, фото, расписание,
                условия и реальные отзывы. Находи удобные локации рядом
                и выходи на игру.
            </p>
            <a class="mskba-venues-scene__cta" href="/venues">
                Найти площадку
                <span aria-hidden="true">→</span>
            </a>
        </div>

        <ul class="mskba-venues-scene__features" aria-label="Возможности поиска площадок">
            <li class="mskba-venues-scene__feature">
                <svg viewBox="0 0 32 32" aria-hidden="true" focusable="false">
                    <path d="M16 29S5 18 5 12a11 11 0 0 1 22 0c0 6-11 17-11 17Z"/>
                    <circle cx="16" cy="12" r="4"/>
                </svg>
                <span>Карта<br>площадок</span>
            </li>
            <li class="mskba-venues-scene__feature">
                <svg viewBox="0 0 32 32" aria-hidden="true" focusable="false">
                    <rect x="3" y="6" width="26" height="23" rx="2"/>
                    <path d="M3 13h26M10 3v7M22 3v7M9 19h4M18 19h4M9 24h4M18 24h4"/>
                </svg>
                <span>Бронирование<br>и расписание</span>
            </li>
            <li class="mskba-venues-scene__feature">
                <svg viewBox="0 0 32 32" aria-hidden="true" focusable="false">
                    <path d="m16 2 4.5 9 10 1.5-7.25 7 1.75 10-9-4.75L7 29.5l1.75-10-7.25-7 10-1.5L16 2Z"/>
                </svg>
                <span>Отзывы<br>и рейтинги</span>
            </li>
            <li class="mskba-venues-scene__feature">
                <svg viewBox="0 0 32 32" aria-hidden="true" focusable="false">
                    <path d="M3 7h26M3 16h26M3 25h26"/>
                    <circle cx="11" cy="7" r="3" fill="currentColor" stroke="none"/>
                    <circle cx="23" cy="16" r="3" fill="currentColor" stroke="none"/>
                    <circle cx="14" cy="25" r="3" fill="currentColor" stroke="none"/>
                    <path d="M11 3v8M23 12v8M14 21v8" stroke="white" stroke-width="1.6"/>
                </svg>
                <span>Фильтры<br>по удобствам</span>
            </li>
        </ul>
    </div>
</section>