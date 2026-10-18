{{-- Trusted, decorative scene backdrop; each PNG shares the same artwork canvas. --}}
{{-- It deliberately begins at the start of the shared Hero + Venues story. --}}
<div class="mskba-home-story__art" aria-hidden="true">
    <img class="mskba-home-story__art-layer mskba-home-story__art-layer--backstage"
        src="{{ asset('images/court_day/homepage/layer-1-backstage.png') }}"
        alt="" decoding="async" loading="eager" fetchpriority="low">
    <img class="mskba-home-story__art-layer mskba-home-story__art-layer--fence"
        src="{{ asset('images/court_day/homepage/layer-2-fense.png') }}"
        alt="" decoding="async" loading="lazy">
    <img class="mskba-home-story__art-layer mskba-home-story__art-layer--hoop"
        src="{{ asset('images/court_day/homepage/layer-3-hoop.png') }}"
        alt="" decoding="async" loading="lazy">
    <img class="mskba-home-story__art-layer mskba-home-story__art-layer--player"
        src="{{ asset('images/court_day/homepage/layer-4-player.png') }}"
        alt="" decoding="async" loading="lazy">
    <div class="mskba-home-story__art-veil"></div>
</div>
