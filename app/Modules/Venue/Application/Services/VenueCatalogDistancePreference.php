<?php

namespace App\Modules\Venue\Application\Services;

use Illuminate\Http\Request;

final class VenueCatalogDistancePreference
{
    /** Keep a stable origin through pagination, never in the account or URL. */
    public function coordinates(Request $request): ?array
    {
        $point = $request->session()->get($this->key($request).'.coordinates');

        return is_array($point) ? $point : null;
    }

    public function locate(Request $request, float $latitude, float $longitude): void
    {
        // Keep a consistent order for all pages in the current session.
        if ($this->coordinates($request) === null) {
            $request->session()->put($this->key($request).'.coordinates', [
                'latitude' => $latitude,
                'longitude' => $longitude,
            ]);
        }
    }

    public function answered(Request $request): bool
    {
        $user = $request->user()?->canonical();

        return $user !== null
            ? (bool) $user->venue_distance_sort_prompt_answered
            : (bool) $request->session()->get($this->key($request).'.answered', false);
    }

    public function answer(Request $request): void
    {
        $user = $request->user()?->canonical();
        if ($user !== null) {
            if (! $user->venue_distance_sort_prompt_answered) {
                $user->forceFill(['venue_distance_sort_prompt_answered' => true])->save();
            }

            return;
        }

        $request->session()->put($this->key($request).'.answered', true);
    }

    public function forget(Request $request): void
    {
        $request->session()->forget($this->key($request).'.coordinates');
    }

    private function key(Request $request): string
    {
        return 'venue_catalog_distance.'.($request->user()?->canonical()->id ?? 'guest');
    }
}
