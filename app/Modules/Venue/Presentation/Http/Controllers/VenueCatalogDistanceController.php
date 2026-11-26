<?php

namespace App\Modules\Venue\Presentation\Http\Controllers;

use App\Modules\Venue\Application\Services\VenueCatalogDistancePreference;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class VenueCatalogDistanceController
{
    public function __invoke(Request $request, VenueCatalogDistancePreference $preference): JsonResponse
    {
        $data = $request->validate([
            'action' => ['required', 'in:locate,answer,forget'],
            'latitude' => ['nullable', 'required_if:action,locate', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'required_if:action,locate', 'numeric', 'between:-180,180'],
        ]);

        if ($data['action'] === 'locate') {
            $preference->locate($request, (float) $data['latitude'], (float) $data['longitude']);
        } elseif ($data['action'] === 'answer') {
            abort_if($preference->coordinates($request) === null, 422);
            $preference->answer($request);
        } else {
            $preference->forget($request);
        }

        return response()->json([
            'available' => $preference->coordinates($request) !== null,
            'answered' => $preference->answered($request),
        ])->header('Cache-Control', 'private, no-store');
    }
}
