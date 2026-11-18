@if(!empty($bannerUrl))
    @php
        $isBackgroundBanner = ($bannerMode ?? 'top') === 'background';
        $bannerOverlayAlpha = max(0, min(1, ((int) ($overlayOpacity ?? 50)) / 100));
    @endphp
    @once
        <link rel="stylesheet" href="{{ asset('css/entity-banner-public.css').'?v=20261118-1' }}">
    @endonce
    <figure class="entity-public-banner{{ $isBackgroundBanner ? ' entity-public-banner--background' : '' }}"
        @if($isBackgroundBanner) style="--entity-banner-overlay-opacity: {{ number_format($bannerOverlayAlpha, 2, '.', '') }}" @endif
        aria-hidden="true">
        <img src="{{ $bannerUrl }}" alt="" loading="eager" decoding="async" fetchpriority="high">
    </figure>
@endif
