<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Prevent the staging/dev copy from entering the search index even when an
 * individual theme or route forgets to emit its own meta robots directive.
 * Real production hosts are configured explicitly in config/seo.php.
 */
final class PreventNonProductionIndexing
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        $hosts = array_map(
            static fn (string $host): string => strtolower($host),
            (array) config('seo.indexable_hosts', ['mskba.ru']),
        );

        if (! in_array(strtolower($request->getHost()), $hosts, true)) {
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');
        }

        return $response;
    }
}
