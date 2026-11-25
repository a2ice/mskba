@php
    // Select branding from the request host, never the deployed Git branch,
    // current design theme, SEO settings or an asset CDN environment.
    $isProductionHost = in_array(strtolower(request()->getHost()), ['mskba.ru', 'www.mskba.ru'], true);
    $faviconPath = $isProductionHost ? '/favicon-production-32.png' : '/favicon-development-32.png';
@endphp
<link rel="icon" type="image/png" sizes="32x32" href="{{ $faviconPath }}?v=20261125">
