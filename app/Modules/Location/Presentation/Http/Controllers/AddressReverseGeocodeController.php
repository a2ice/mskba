<?php

namespace App\Modules\Location\Presentation\Http\Controllers;

use App\Modules\Location\Application\Services\AddressSuggestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

final class AddressReverseGeocodeController
{
    public function __invoke(Request $request, AddressSuggestService $service): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();

        try {
            $suggestion = $service->reverse((float) $data['latitude'], (float) $data['longitude']);
        } catch (\Throwable $exception) {
            report($exception);
            $suggestion = null;
        }

        return response()->json(['suggestion' => $suggestion]);
    }
}
