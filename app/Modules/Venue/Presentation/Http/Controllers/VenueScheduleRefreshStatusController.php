<?php

namespace App\Modules\Venue\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\VenueIntegration\Application\Services\VisitScheduleRefresh;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class VenueScheduleRefreshStatusController extends Controller
{
    public function __invoke(Request $request, int $venue, VisitScheduleRefresh $refresh): JsonResponse
    {
        abort_unless(Venue::query()->find($venue)?->isPubliclyVisible(), 404);
        $data = $request->validate(['court' => ['nullable', 'integer', 'min:1']]);

        return response()->json(['courts' => (object) $refresh->forVenue(
            $venue, isset($data['court']) ? (int) $data['court'] : null,
        )])->header('Cache-Control', 'no-store');
    }
}
