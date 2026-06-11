<?php

namespace App\Modules\Location\Presentation\Http\Controllers;

use App\Modules\Location\Application\Services\AddressSuggestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

final class AddressSuggestController
{
    public function __invoke(Request $request, AddressSuggestService $service): JsonResponse
    {
        $validator = Validator::make($request->query->all(), [
            'query' => ['required', 'string', 'min:3', 'max:255'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();

        try {
            $suggestions = $service->suggest($data['query']);
        } catch (\Throwable $exception) {
            report($exception);
            $suggestions = [];
        }

        return response()->json([
            'suggestions' => $suggestions,
        ]);
    }
}
