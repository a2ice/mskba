<?php

namespace App\Modules\Content\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Content\Application\Services\FaqHelpCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class FaqHelpController extends Controller
{
    public function __invoke(Request $request, FaqHelpCatalog $catalog): JsonResponse
    {
        $data = $request->validate([
            'context' => 'nullable|string|max:50',
            'section' => 'nullable|string|max:50',
            'article' => 'nullable|string|max:255',
        ]);
        return response()->json([
            ...$catalog->content($data['context'] ?? null, $data['section'] ?? null, $data['article'] ?? null),
            'authenticated' => $request->user() !== null,
            'csrf_token' => csrf_token(),
        ]);
    }
}
