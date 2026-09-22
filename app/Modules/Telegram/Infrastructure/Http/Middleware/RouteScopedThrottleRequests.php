<?php

namespace App\Modules\Telegram\Infrastructure\Http\Middleware;

use Illuminate\Routing\Middleware\ThrottleRequests;

final class RouteScopedThrottleRequests extends ThrottleRequests
{
    /**
     * Numeric Laravel throttles use only the authenticated user or client IP
     * as their request signature. Without a route suffix, attempts against
     * unrelated endpoints consume the same counter and one authentication
     * method can lock every other method for the same client.
     */
    protected function resolveRequestSignature($request)
    {
        $signature = parent::resolveRequestSignature($request);
        $route = $request->route();
        $routeScope = $route?->getName();

        if (! is_string($routeScope) || $routeScope === '') {
            $methods = implode('|', $route?->methods() ?? [$request->getMethod()]);
            $routeScope = $methods.'|'.($route?->uri() ?? $request->path());
        }

        return $signature.'|'.$routeScope;
    }
}
