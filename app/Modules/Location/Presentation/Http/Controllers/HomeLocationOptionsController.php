<?php

namespace App\Modules\Location\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Location\Domain\Models\City;
use App\Modules\Location\Domain\Models\MetroStation;
use Illuminate\Http\JsonResponse;

final class HomeLocationOptionsController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $cities = City::query()
            ->with(['districts' => fn ($query) => $query->orderBy('name')])
            ->orderBy('name')
            ->get()
            ->map(fn (City $city): array => [
                'id' => $city->id,
                'name' => $city->name,
                'alias' => $city->alias,
                'short_name' => $city->short_name,
                'districts' => $city->districts
                    ->map(fn ($district): array => [
                        'id' => $district->id,
                        'name' => $district->name,
                        'alias' => $district->alias,
                        'short_name' => $district->short_name,
                    ])
                    ->values()
                    ->all(),
            ])
            ->values()
            ->all();

        $metroStations = MetroStation::query()
            ->with('line')
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->orderBy('name')
            ->orderBy('metro_line_id')
            ->get()
            ->map(fn (MetroStation $station): array => [
                'id' => (int) $station->id,
                'name' => $station->name,
                'line_name' => $station->line?->name,
                'line_color' => $station->line?->color,
                'latitude' => (float) $station->latitude,
                'longitude' => (float) $station->longitude,
            ])
            ->values()
            ->all();

        $timezone = (string) config('app.timezone', 'Europe/Moscow');
        $today = now($timezone)->startOfDay();

        return response()->json([
            'cities' => $cities,
            'metro_stations' => $metroStations,
            'address_suggest_url' => route('integrations.address-suggest'),
            'address_reverse_url' => route('integrations.address-reverse'),
            'timezone' => $timezone,
            'default_date_from' => $today->toDateString(),
            'default_date_to' => $today->copy()->addDays(7)->toDateString(),
        ]);
    }
}
