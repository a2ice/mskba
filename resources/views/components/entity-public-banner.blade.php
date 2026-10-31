@if(!empty($bannerUrl))
    @once
        <link rel="stylesheet" href="{{ asset('css/entity-banner-public.css') }}">
    @endonce
    <figure class="entity-public-banner">
        <img src="{{ $bannerUrl }}" alt="" loading="eager" decoding="async" fetchpriority="high">
    </figure>
@endif
