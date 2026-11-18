@if(!empty($bannerUrl))
    @php
        $isBackgroundBanner = ($bannerMode ?? 'top') === 'background';
        $bannerOverlayCss = (string) ($overlayCss ?? 'rgba(0,0,0,0.5)');
    @endphp
    @once
        <link rel="stylesheet" href="{{ asset('css/entity-banner-public.css').'?v=20261118-2' }}">
    @endonce
    <figure class="entity-public-banner{{ $isBackgroundBanner ? ' entity-public-banner--background' : '' }}"
        @if($isBackgroundBanner) data-banner-overlay-css="{{ $bannerOverlayCss }}" @endif
        aria-hidden="true">
        <img src="{{ $bannerUrl }}" alt="" loading="eager" decoding="async" fetchpriority="high">
    </figure>
    @if($isBackgroundBanner)
        @once
            <script defer src="{{ asset('js/entity-banner-public.js').'?v=20261118-2' }}"></script>
        @endonce
    @endif
@endif
