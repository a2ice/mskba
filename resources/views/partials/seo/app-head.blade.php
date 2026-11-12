@php
    // Preserve the established dark-theme SEO hierarchy for the new app theme.
    $seoRouteName = Route::currentRouteName() ?? 'default';
    $seoRoute = (array) (config('seo.pages', [])[$seoRouteName] ?? []);
    $seoPageTitle = isset($metaTitle) && filled($metaTitle)
        ? (string) $metaTitle
        : ($seoRoute['title'] ?? (isset($title) ? $title.' · MSKBA' : 'MSKBA'));
    $seoExplicitDescription = isset($metaDescription) ? trim((string) $metaDescription) : '';
    $seoRouteDescription = trim((string) ($seoRoute['description'] ?? ''));
    $seoPageDescription = $seoExplicitDescription !== ''
        ? $seoExplicitDescription
        : ($seoRouteDescription !== '' ? $seoRouteDescription : trim((string) config('seo.default_description', '')));
    $seoPageKeywords = isset($metaKeywords) ? trim((string) $metaKeywords) : null;
    $seoPageCanonical = $canonicalUrl ?? url()->current();
    $seoPageImage = ! empty($metaImage) ? $metaImage : asset((string) config('seo.default_image', 'images/bg-home-welcome-screen.png'));
    $seoPageImageAlt = isset($metaImageAlt) && filled($metaImageAlt) ? trim((string) $metaImageAlt) : $seoPageTitle;

    $seoIsIndexableHost = in_array(
        strtolower(request()->getHost()),
        array_map('strtolower', (array) config('seo.indexable_hosts', ['mskba.ru'])),
        true,
    );
    $seoPageRobots = $seoIsIndexableHost
        ? (isset($metaRobots) ? trim((string) $metaRobots) : null)
        : 'noindex, nofollow, noarchive';
    $seoStructuredDataItems = [];

    if ($seoRouteName === 'welcome') {
        $seoStructuredDataItems[] = [
            '@context' => 'https://schema.org',
            '@type' => 'SportsOrganization',
            'name' => (string) config('seo.site_name', 'MSKBA'),
            'url' => route('welcome'),
            'logo' => asset((string) config('seo.organization_logo', 'images/logo.png')),
            'sameAs' => array_values(array_filter((array) config('seo.same_as', []))),
        ];
        $seoStructuredDataItems[] = [
            '@context' => 'https://schema.org',
            '@type' => 'WebSite',
            'name' => (string) config('seo.site_name', 'MSKBA'),
            'url' => route('welcome'),
            'inLanguage' => (string) config('seo.html_lang', 'ru'),
        ];
    }

    if (! empty($structuredData)) {
        $seoAdditionalJsonLd = isset($structuredData['@context']) ? [$structuredData] : $structuredData;
        foreach ((array) $seoAdditionalJsonLd as $seoJsonLdItem) {
            if (is_array($seoJsonLdItem)) {
                $seoStructuredDataItems[] = $seoJsonLdItem;
            }
        }
    }
@endphp
<title>{{ $seoPageTitle }}</title>
@if($seoPageDescription)
    <meta name="description" content="{{ $seoPageDescription }}">
@endif
@if($seoPageKeywords)
    <meta name="keywords" content="{{ $seoPageKeywords }}">
@endif
@if($seoPageRobots)
    <meta name="robots" content="{{ $seoPageRobots }}">
@endif
<link rel="canonical" href="{{ $seoPageCanonical }}">
@if(! empty($paginationPrevUrl))
    <link rel="prev" href="{{ $paginationPrevUrl }}">
@endif
@if(! empty($paginationNextUrl))
    <link rel="next" href="{{ $paginationNextUrl }}">
@endif
<meta name="yandex-verification" content="5e74a0d5140e0b49">
<meta property="og:site_name" content="{{ config('seo.site_name', 'MSKBA') }}">
<meta property="og:locale" content="{{ config('seo.og_locale', 'ru_RU') }}">
<meta property="og:title" content="{{ $seoPageTitle }}">
@if($seoPageDescription)
    <meta property="og:description" content="{{ $seoPageDescription }}">
@endif
<meta property="og:url" content="{{ $seoPageCanonical }}">
<meta property="og:type" content="{{ $metaType ?? 'website' }}">
<meta property="og:image" content="{{ $seoPageImage }}">
<meta property="og:image:alt" content="{{ $seoPageImageAlt }}">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="{{ $seoPageTitle }}">
@if($seoPageDescription)
    <meta name="twitter:description" content="{{ $seoPageDescription }}">
@endif
<meta name="twitter:image" content="{{ $seoPageImage }}">
<meta name="twitter:image:alt" content="{{ $seoPageImageAlt }}">
@foreach($seoStructuredDataItems as $seoStructuredDataItem)
    <script type="application/ld+json">{!! json_encode($seoStructuredDataItem, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
@endforeach
@if($seoIsIndexableHost)
    @include('partials.analytics.yandex-metrika')
@endif
