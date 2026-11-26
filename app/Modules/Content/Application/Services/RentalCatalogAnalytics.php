<?php

namespace App\Modules\Content\Application\Services;

use App\Support\Privacy\CookieConsent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Aggregate successful catalogue HTML requests, not unique visitors or
 * attributable bookings. No individual history or external telemetry.
 */
final class RentalCatalogAnalytics
{
    public function record(Request $request, ?int $landingId, array $filters, int $total): void
    {
        if (! config('features.seo_rental_analytics.enabled', false)
            || ! $request->isMethod('GET')
            || $request->expectsJson()
            || $request->routeIs('admin.content.catalog-landings.preview')
            || (int) ($filters['page'] ?? 1) !== 1
            || isset($filters['fragment'])
            || str_contains(strtolower($request->header('Sec-Purpose', '').' '.$request->header('Purpose', '')), 'prefetch')
            || str_contains(strtolower($request->header('Sec-Purpose', '')), 'prerender')) {
            return;
        }

        try {
            if (! CookieConsent::internalAnalyticsAllowed($request)) {
                return;
            }

            $events = [];
            if ($landingId !== null) {
                $events[] = 'seo_landing_view';
            } elseif (array_filter(array_intersect_key($filters, array_flip([
                'search', 'city_id', 'district_id', 'type', 'operational_status',
                'access', 'rental_configured', 'has_upcoming_events', 'my_interests',
                'latitude', 'longitude', 'radius_km',
            ])), static fn ($value) => filled($value) && $value !== false && $value !== '0' && $value !== 0) !== []) {
                $events[] = 'venue_search';
            }

            if ($events === []) {
                return;
            }
            if ($total === 0) {
                $events[] = 'venue_search_empty';
            }

            $source = $this->source($request);
            $day = now()->toDateString();
            DB::transaction(function () use ($events, $source, $day, $landingId): void {
                foreach ($events as $event) {
                    $key = ['day' => $day, 'event' => $event, 'landing_id' => $landingId ?? 0, 'source' => $source];
                    DB::table('seo_rental_daily_metrics')->insertOrIgnore($key + ['hits' => 0]);
                    DB::table('seo_rental_daily_metrics')->where($key)->increment('hits');
                }
            });
        } catch (Throwable $exception) {
            // Analytics must not make the catalogue unavailable. Do not log
            // input, URLs, credentials, query text or database bindings.
            Log::warning('Rental catalogue analytics unavailable', ['exception_type' => $exception::class]);
        }
    }

    private function source(Request $request): string
    {
        // This is the immediate referrer category, not organic/paid attribution.
        $host = strtolower((string) parse_url((string) $request->header('Referer', ''), PHP_URL_HOST));
        if ($host === '') {
            return 'direct_or_unknown';
        }
        if ($host === strtolower($request->getHost()) || in_array($host, ['mskba.ru', 'www.mskba.ru', 'dev.mskba.ru'], true)) {
            return 'internal';
        }
        if (preg_match('/(?:^|\\.)yandex\\.(?:ru|com|by|kz|uz|net)$/D', $host) || $host === 'ya.ru') {
            return 'yandex_referrer';
        }
        if (preg_match('/(?:^|\\.)google\\.(?:com|ru|[a-z]{2}|co\\.[a-z]{2}|com\\.[a-z]{2})$/D', $host)) {
            return 'google_referrer';
        }

        return 'other_referrer';
    }
}
