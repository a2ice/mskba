<?php

namespace App\Support\Features;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class EnsureVenueRentalFeatureEnabled
{
    public function __construct(private FeatureFlags $features, private VenueRentalRollout $rollout) {}

    public function handle(Request $request, Closure $next, string $feature): Response
    {
        $feature = VenueRentalFeature::tryFrom($feature);

        $venue = $request->route('venue');
        $venueId = is_object($venue) ? (int) $venue->getKey() : (is_numeric($venue) ? (int) $venue : null);
        $contract = $request->route('contract');
        $contractId = is_object($contract) ? (int) $contract->getKey() : (is_numeric($contract) ? (int) $contract : null);
        $stableKey = (string) ($request->user()?->canonical()->id ?? $venueId ?? $request->ip());

        if ($feature !== null
            && $this->features->enabled($feature)
            && $this->rollout->allows($feature, $request->user(), $venueId, $contractId, $stableKey, ! $request->isMethodSafe())) {
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
