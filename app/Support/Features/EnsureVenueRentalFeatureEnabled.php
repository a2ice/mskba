<?php

namespace App\Support\Features;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class EnsureVenueRentalFeatureEnabled
{
    public function __construct(private FeatureFlags $features) {}

    public function handle(Request $request, Closure $next, string $feature): Response
    {
        $feature = VenueRentalFeature::tryFrom($feature);

        if ($feature !== null && $this->features->enabled($feature)) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return new JsonResponse([
                'message' => 'feature_disabled',
                'code' => 'feature_disabled',
            ], Response::HTTP_NOT_FOUND);
        }

        abort(Response::HTTP_NOT_FOUND);
    }
}
