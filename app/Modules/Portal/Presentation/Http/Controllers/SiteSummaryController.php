<?php

namespace App\Modules\Portal\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Portal\Application\Services\SiteSummaryService;
use Illuminate\Http\JsonResponse;

final class SiteSummaryController extends Controller
{
    public function __invoke(SiteSummaryService $summary): JsonResponse
    {
        return response()->json($summary->get()->toArray());
    }
}
