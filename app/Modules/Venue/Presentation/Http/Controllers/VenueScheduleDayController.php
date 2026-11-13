<?php

namespace App\Modules\Venue\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Venue\Application\Services\PublicVenueDaySchedule;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\Venue\Domain\Models\VenueCourt;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

final class VenueScheduleDayController extends Controller
{
    public function __invoke(Request $request, Venue $venue, PublicVenueDaySchedule $days): JsonResponse
    {
        $isAdmin = $request->user()?->hasSystemRole(UserSystemRoleEnum::SUPERADMIN) ?? false;
        abort_unless($venue->status === VenueStatusEnum::CONFIRMED || $isAdmin, 404);

        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'venue_court_id' => ['nullable', 'integer',
                Rule::exists('venue_courts', 'id')->where(fn ($query) =>
                    $query->where('venue_id', $venue->id)->whereNull('deleted_at')),
            ],
        ]);

        $court = isset($data['venue_court_id'])
            ? VenueCourt::query()->where('venue_id', $venue->id)->findOrFail((int) $data['venue_court_id'])
            : ($venue->primaryCourt()->first() ?? $venue->courts()->firstOrFail());

        try {
            return response()->json($days->forDate($venue, $court, $data['date']))
                ->header('Cache-Control', 'private, no-store');
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422)
                ->header('Cache-Control', 'no-store');
        }
    }
}
