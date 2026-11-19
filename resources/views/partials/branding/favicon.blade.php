@php
    // The public portal keeps its own recognizable tab icon regardless of theme.
    $isProductionHost = in_array(strtolower(request()->getHost()), ['mskba.ru', 'www.mskba.ru'], true);
@endphp
@if($isProductionHost)
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-production-32.png') }}?v=20261118">
@else
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
    @if($includeLegacyPng ?? false)
        <link rel="icon" href="{{ asset('favicon-32x32.png') }}" type="image/png">
    @endif
@endif
