<?php

namespace App\Modules\Location\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Location\Domain\Models\City;
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

        return response()->json([
            'cities' => $cities,
            'address_suggest_url' => route('integrations.address-suggest'),
            'address_reverse_url' => route('integrations.address-reverse'),
        ]);
    }
}
