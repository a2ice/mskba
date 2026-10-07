@php
    $theme = app(\App\Presentation\Theming\ThemeResolver::class);
    $routeName = Route::currentRouteName() ?? 'default';
    $routeSeo = (config('seo.pages', [])[$routeName] ?? []);
    $pageTitle = isset($metaTitle)
        ? $metaTitle
        : ($routeSeo['title'] ?? (isset($title) ? $title.' · '.config('app.name', 'MSKBA') : config('app.name', 'MSKBA')));
    $explicitDescription = isset($metaDescription) ? trim((string) $metaDescription) : '';
    $routeDescription = isset($routeSeo['description']) ? trim((string) $routeSeo['description']) : '';
    $pageDescription = $explicitDescription !== ''
        ? $explicitDescription
        : ($routeDescription !== '' ? $routeDescription : trim((string) config('seo.default_description', '')));
    $pageKeywords = isset($metaKeywords) ? trim((string) $metaKeywords) : null;
    $pageCanonical = $canonicalUrl ?? url()->current();
    $pageRobots = isset($metaRobots) ? trim((string) $metaRobots) : null;
    $pageImage = ! empty($metaImage)
        ? $metaImage
        : asset((string) config('seo.default_image', 'images/bg-home-welcome-screen.png'));
    $pageImageAlt = isset($metaImageAlt) && filled($metaImageAlt) ? trim((string) $metaImageAlt) : $pageTitle;
    $structuredDataItems = [];

    if ($routeName === 'welcome') {
        $structuredDataItems[] = [
            '@context' => 'https://schema.org',
            '@type' => 'SportsOrganization',
            'name' => (string) config('seo.site_name', 'MSKBA'),
            'url' => route('welcome'),
            'logo' => asset((string) config('seo.organization_logo', 'images/logo.png')),
            'sameAs' => array_values(array_filter((array) config('seo.same_as', []))),
        ];
        $structuredDataItems[] = [
            '@context' => 'https://schema.org',
            '@type' => 'WebSite',
            'name' => (string) config('seo.site_name', 'MSKBA'),
            'url' => route('welcome'),
            'inLanguage' => (string) config('seo.html_lang', 'ru'),
        ];
    }

    if (! empty($structuredData)) {
        $additionalStructuredData = isset($structuredData['@context']) ? [$structuredData] : $structuredData;
        foreach ((array) $additionalStructuredData as $structuredDataItem) {
            if (is_array($structuredDataItem)) {
                $structuredDataItems[] = $structuredDataItem;
            }
        }
    }

    $contextManagementPlacement = $contextManagementPlacement ?? 'top';

    $routeClass = 'page-'.str_replace('.', '-', $routeName);

    $isTelegramMiniApp = ($telegramMiniApp ?? false) === true
        || session()->get('telegram_mini_app_context') === true;
    $shouldBootstrapTelegramAuth = ($telegramAuthBootstrap ?? false) === true;
    $isMainPage = Route::currentRouteName() === 'welcome'
        || Route::currentRouteName() === 'integrations.telegram.main';

    $routeClass .= $isMainPage ? ' main' : '';
    $routeClass .= $isTelegramMiniApp ? ' telegram-mini-app' : '';

    $user = auth()->user()?->canonical();
    $user?->loadMissing('profile.activeAvatar');
    $headerTelegramAccount = $user
        ? \App\Modules\Telegram\Domain\Models\TelegramAccount::query()
            ->whereIn('user_id', $user->identityIds())
            ->orderByRaw('CASE WHEN user_id = ? THEN 0 ELSE 1 END', [$user->id])
            ->orderByDesc('last_auth_at')
            ->orderByDesc('updated_at')
            ->first()
        : null;

    $userProfileName = $user
        ? trim(implode(' ', array_filter([
            $user->profile?->first_name,
            $user->profile?->last_name,
        ], static fn ($value) => filled($value))))
        : '';
    $userLoginLabel = $user ? ($userProfileName ?: $user->username) : 'Войти';
@endphp

<!DOCTYPE html>
<html lang="{{ config('seo.html_lang', 'ru') }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
        <meta name="theme-color" content="#10120f">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
        <link rel="icon" href="{{ asset('favicon-32x32.png') }}" type="image/png">
        <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
        <link rel="manifest" href="{{ asset('site.webmanifest') }}">
        <meta name="yandex-verification" content="5e74a0d5140e0b49" />
        <title>{{ $pageTitle }}</title>
        @if($pageDescription)
            <meta name="description" content="{{ $pageDescription }}">
        @endif
        @if($pageKeywords)
            <meta name="keywords" content="{{ $pageKeywords }}">
        @endif
        @if($pageRobots)
            <meta name="robots" content="{{ $pageRobots }}">
        @endif
        <link rel="canonical" href="{{ $pageCanonical }}">
        @if(! empty($paginationPrevUrl))
            <link rel="prev" href="{{ $paginationPrevUrl }}">
        @endif
        @if(! empty($paginationNextUrl))
            <link rel="next" href="{{ $paginationNextUrl }}">
        @endif
        <meta property="og:site_name" content="{{ config('seo.site_name', 'MSKBA') }}">
        <meta property="og:locale" content="{{ config('seo.og_locale', 'ru_RU') }}">
        <meta property="og:title" content="{{ $pageTitle }}">
        @if($pageDescription)
            <meta property="og:description" content="{{ $pageDescription }}">
        @endif
        <meta property="og:url" content="{{ $pageCanonical }}">
        <meta property="og:type" content="{{ $metaType ?? 'website' }}">
        <meta property="og:image" content="{{ $pageImage }}">
        <meta property="og:image:alt" content="{{ $pageImageAlt }}">
        <meta name="twitter:card" content="summary_large_image">
        <meta name="twitter:title" content="{{ $pageTitle }}">
        @if($pageDescription)
            <meta name="twitter:description" content="{{ $pageDescription }}">
        @endif
        <meta name="twitter:image" content="{{ $pageImage }}">
        <meta name="twitter:image:alt" content="{{ $pageImageAlt }}">
        @foreach($structuredDataItems as $structuredDataItem)
            <script type="application/ld+json">{!! json_encode($structuredDataItem, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
        @endforeach
        @include('partials.analytics.yandex-metrika')
        @if($isTelegramMiniApp)
            <script async src="https://telegram.org/js/telegram-web-app.js" data-telegram-sdk></script>
        @endif
        @vite($theme->viteInputs())
        @yield('styles')
    </head>
    <body
        class="theme-shell {{ $routeClass }}"
        style="--site-body-bottom-bg: url('{{ asset('images/bg-indoor.png') }}');"
        @if($isTelegramMiniApp)
            data-telegram-mini-app
            data-account-url="{{ route('account') }}"
            @if($shouldBootstrapTelegramAuth)
                data-telegram-auth-bootstrap
                data-telegram-auth-url="{{ route('integrations.telegram.auth') }}"
            @endif
        @endif
        data-site-summary-url="{{ route('site-summary.heartbeat') }}"
        data-site-summary-heartbeat-interval="{{ max(30, (int) config('site_summary.heartbeat_interval_seconds', 45)) }}"
    >
        @include('partials.analytics.yandex-metrika-noscript')

        @if($shouldBootstrapTelegramAuth)
            <div
                class="telegram-auth-bootstrap"
                data-telegram-bootstrap-screen
                role="status"
                aria-live="polite"
                aria-label="Открываем страницу Mini App"
            >
                <span class="telegram-auth-bootstrap__spinner" aria-hidden="true"></span>
                <strong class="telegram-auth-bootstrap__title">Открываем MSKBA</strong>
                <span class="telegram-auth-bootstrap__status" data-telegram-bootstrap-status>
                    Проверяем данные Telegram…
                </span>
            </div>
        @endif

        <div class="site-frame">
            @include('theme::partials.header')

            <main class="site-content">
                @if(! empty($contextManagementUrl) && $contextManagementPlacement === 'top')
                    <div class="inner py-3 d-flex justify-content-end" data-context-management-action>
                        <a class="btn btn--secondary btn--sm" href="{{ $contextManagementUrl }}">
                            <i class="ti ti-settings" aria-hidden="true"></i>
                            {{ $contextManagementLabel ?? 'Управление' }}
                        </a>
                    </div>
                @endif
                @yield('content')
                @if(in_array(Route::currentRouteName(), ['venues.show', 'venues.courts.show'], true) && isset($venue))
                    @include('theme::partials.venues.characteristics-public', ['venue' => $venue])
                @endif
            </main>

            @include('theme::partials.mobile-primary-bar')

            <footer class="site-footer">
                @include('theme::partials.footer')
            </footer>
        </div>

        @if($isMainPage)
            <div class="home-event-venue-selector-source" data-home-event-venue-selector-source>
                @include('theme::partials.venues.predictive-selector', [
                    'id' => 'homeEventVenue',
                    'name' => 'home_event_venue_id',
                    'label' => 'Площадка',
                    'selectedVenue' => null,
                    'confirmedOnly' => true,
                    'operationalStatus' => \App\Modules\Venue\Domain\Enums\VenueOperationalStatusEnum::ACTIVE->value,
                    'required' => false,
                    'showBookingScope' => false,
                    'showFavorites' => false,
                    'showMetroFilter' => true,
                ])
            </div>

            <div class="home-venue-selector-source" data-home-venue-selector-source>
                @include('theme::partials.venues.predictive-selector', [
                    'id' => 'homeVenueSearch',
                    'name' => 'home_venue_id',
                    'label' => 'Площадка',
                    'selectedVenue' => null,
                    'confirmedOnly' => true,
                    'operationalStatus' => \App\Modules\Venue\Domain\Enums\VenueOperationalStatusEnum::ACTIVE->value,
                    'required' => false,
                    'showBookingScope' => false,
                    'showFavorites' => false,
                    'showMetroFilter' => true,
                ])
            </div>

            <div class="home-venue-section-selector-source" data-home-venue-section-selector-source>
                @include('theme::partials.venues.predictive-selector', [
                    'id' => 'homeVenueSectionSearch',
                    'name' => 'home_venue_section_id',
                    'label' => 'Найти площадку',
                    'selectedVenue' => null,
                    'confirmedOnly' => true,
                    'operationalStatus' => \App\Modules\Venue\Domain\Enums\VenueOperationalStatusEnum::ACTIVE->value,
                    'required' => false,
                    'showBookingScope' => false,
                    'showFavorites' => false,
                    'showMetroFilter' => true,
                ])
            </div>
        @endif

        @include('theme::partials.modal')
        @include('theme::partials.notification-toasts')
    </body>
</html>