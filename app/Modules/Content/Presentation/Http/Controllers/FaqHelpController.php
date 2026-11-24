<?php

namespace App\Modules\Content\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Ai\Application\Queries\GetPlayerAvatarGenerationOffer;
use App\Modules\Content\Application\Services\FaqHelpCatalog;
use App\Modules\Content\Application\Services\PageFaqHelpCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class FaqHelpController extends Controller
{
    public function __invoke(Request $request, FaqHelpCatalog $catalog, PageFaqHelpCatalog $pageCatalog): JsonResponse
    {
        $data = $request->validate([
            'context' => 'nullable|string|max:50',
            'section' => 'nullable|string|max:50',
            'article' => 'nullable|string|max:255',
            'page_key' => 'nullable|string|max:80',
        ]);
        return response()->json([
            ...$catalog->content($data['context'] ?? null, $data['section'] ?? null, $data['article'] ?? null),
            'page_help' => $pageCatalog->content($data['page_key'] ?? null),
            'bonus_opportunities' => app(GetPlayerAvatarGenerationOffer::class)->bonusOpportunities(),
            'authenticated' => $request->user() !== null,
            'csrf_token' => csrf_token(),
        ]);
    }
}
