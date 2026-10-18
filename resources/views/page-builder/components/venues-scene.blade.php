{{-- Single venues scene; scroll animation progressive-enhancement leaves all content accessible. --}}
<section class="mskba-venues-scene" id="venues-intro" data-mskba-venues-scene
    aria-labelledby="mskba-venues-scene-title">
    {{-- Marker for Ball Director v2: one responsive point in scene coordinates. --}}
    <span class="mskba-venues-scene__ball-target" data-mskba-ball-target aria-hidden="true"></span>
    <div class="container mskba-venues-scene__inner">
        <div class="mskba-venues-scene__visual-space" aria-hidden="true"></div>
        <div class="mskba-venues-scene__copy">
            <span class="mskba-venues-scene__ghost" aria-hidden="true">ПЛОЩАДКИ</span>
            <p class="mskba-venues-scene__eyebrow">ИГРАЙ В ЛЮБОМ РАЙОНЕ</p>
            <h2 class="mskba-venues-scene__title" id="mskba-venues-scene-title">ПЛОЩАДКИ</h2>
            <p class="mskba-venues-scene__description">
                Открытые и крытые площадки Москвы: карта, фото, расписание,
                условия и реальные отзывы. Находи удобные локации рядом
                и выходи на игру.
            </p>
            <a class="mskba-venues-scene__cta" href="/venues">
                Найти площадку
                <svg aria-hidden="true" viewBox="0 0 24 24" fill="none">
                    <path d="M4 12h15m-6-6 6 6-6 6" stroke="currentColor"
                        stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            </a>
            <nav class="mskba-venues-scene__highlights" aria-label="Возможности раздела Площадки">
                <a href="/venues">
                    <svg viewBox="0 0 32 32" fill="none" aria-hidden="true">
                        <path d="M16 29s10-10.1 10-17A10 10 0 0 0 6 12c0 6.9 10 17 10 17Z"
                            stroke="currentColor" stroke-width="1.8"/>
                        <circle cx="16" cy="12" r="3.6" stroke="currentColor" stroke-width="1.8"/>
                    </svg>
                    <span>Карта<br>площадок</span>
                </a>
                <a href="/venues">
                    <svg viewBox="0 0 32 32" fill="none" aria-hidden="true">
                        <rect x="4.5" y="7" width="23" height="21" rx="2" stroke="currentColor" stroke-width="1.8"/>
                        <path d="M4.5 13h23M10 3.5v7M22 3.5v7M10 18h3m5 0h3m-11 5h3"
                            stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
                    </svg>
                    <span>Бронирование<br>и расписание</span>
                </a>
                <a href="/venues">
                    <svg viewBox="0 0 32 32" fill="none" aria-hidden="true">
                        <path d="m16 3.5 4 8.3 9.1 1.3-6.55 6.45 1.55 9.1L16 24.4l-8.1 4.25 1.55-9.1L2.9 13.1l9.1-1.3L16 3.5Z"
                            stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/>
                    </svg>
                    <span>Отзывы<br>и рейтинги</span>
                </a>
                <a href="/venues">
                    <svg viewBox="0 0 32 32" fill="none" aria-hidden="true">
                        <path d="M4 9h24M4 16h24M4 23h24"
                            stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
                        <circle cx="11" cy="9" r="3" fill="#fff" stroke="currentColor" stroke-width="1.8"/>
                        <circle cx="22" cy="16" r="3" fill="#fff" stroke="currentColor" stroke-width="1.8"/>
                        <circle cx="14" cy="23" r="3" fill="#fff" stroke="currentColor" stroke-width="1.8"/>
                    </svg>
                    <span>Фильтры<br>по удобствам</span>
                </a>
            </nav>
        </div>
    </div>
</section>
