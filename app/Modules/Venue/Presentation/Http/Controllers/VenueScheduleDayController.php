<?php

namespace App\Modules\Venue\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Event\Domain\Enums\VenueBookingScopeEnum;
use App\Modules\VenueBooking\Application\Services\VenuePersonalPricing;
use App\Modules\VenueBooking\Domain\Exceptions\VenueBookingPolicyException;
use Carbon\CarbonImmutable;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Venue\Application\Services\PublicVenueDaySchedule;
use App\Modules\Venue\Application\Services\VenueAccessResolver;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
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
        $viewerActor = app(CurrentActorResolver::class)->resolveForRequest($request);
        $mayPreview = app(VenueAccessResolver::class)->canManage(
            $request->user(), $venue, $viewerActor,
        );
        abort_unless($venue->isPubliclyVisible() || $mayPreview
            || ($venue->is_published && $isAdmin), 404);

        $data = $request->validate([
            'coupon_code' => ['nullable', 'string', 'max:32', 'regex:/^[A-Za-z0-9_-]+$/'],
            'date' => ['required', 'date_format:Y-m-d'],
            'search_until' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date'],
            'search_from_time' => ['required_with:search_until', 'nullable', 'date_format:H:i'],
            'search_to_time' => ['required_with:search_until', 'nullable', 'date_format:H:i'],
            'preview_start' => ['nullable', 'date_format:H:i', 'required_with:preview_duration_minutes'],
            'preview_duration_minutes' => ['nullable', 'integer', 'min:30', 'max:1440', 'required_with:preview_start'],
            'venue_court_id' => ['nullable', 'integer',
                Rule::exists('venue_courts', 'id')->where(fn ($query) =>
                    $query->where('venue_id', $venue->id)->whereNull('deleted_at')),
            ],
        ]);

        $court = isset($data['venue_court_id'])
            ? VenueCourt::query()->where('venue_id', $venue->id)->findOrFail((int) $data['venue_court_id'])
            : ($venue->primaryCourt()->first() ?? $venue->courts()->firstOrFail());

        try {
            // One bounded request per court. Return only the data needed to
            // summarize the search; detailed booking information is fetched
            // separately by the existing day dialog.
            if (isset($data['search_until'])) {
                $first = CarbonImmutable::createFromFormat('!Y-m-d', $data['date'], 'Europe/Moscow');
                $last = CarbonImmutable::createFromFormat('!Y-m-d', $data['search_until'], 'Europe/Moscow');
                $today = CarbonImmutable::now('Europe/Moscow')->startOfDay();
                $endClock = $data['search_to_time'] === '23:59' ? '24:00' : $data['search_to_time'];
                if ($first->lessThan($today) || $last->greaterThan($today->addDays(8))
                    || ($first->equalTo($last) && $data['search_from_time'] >= $endClock)
                    || isset($data['preview_start'])) {
                    throw new InvalidArgumentException('Выбери корректный диапазон в пределах доступных девяти дней.');
                }
                $searchDays = [];
                for ($day = $first; $day->lessThanOrEqualTo($last); $day = $day->addDay()) {
                    $dayResult = $days->forDate($venue, $court, $day->toDateString(), null, null,
                        $request->user(), $viewerActor);
                    $lower = $day->equalTo($first) ? $data['search_from_time'] : '00:00';
                    $upper = $day->equalTo($last) ? $endClock : '24:00';
                    $slots = [];
                    foreach ($dayResult['slots'] as $slot) {
                        if ($slot['starts_at'] >= $lower && $slot['ends_at'] <= $upper) {
                            $slots[] = array_intersect_key($slot, array_flip(['starts_at', 'ends_at', 'status', 'selectable']));
                        }
                    }
                    $searchDays[] = array_intersect_key($dayResult, array_flip(['date', 'step_minutes',
                        'minimum_minutes', 'maximum_minutes', 'booking_enabled', 'stale', 'refresh_state', 'occupancy_requires_confirmation']))
                        + ['slots' => $slots];
                }

                return response()->json(['days' => $searchDays, 'venue_court_id' => $court->id])
                    ->header('Cache-Control', 'private, no-store');
            }
            $result = $days->forDate(
                $venue, $court, $data['date'],
                $data['preview_start'] ?? null, $data['preview_duration_minutes'] ?? null,
                $request->user(), $viewerActor,
            );
            if ($result['price_preview'] !== null) {
                $baseline = $result['price_preview']['total_minor'];
                $personal = app(VenuePersonalPricing::class)->evaluate(
                    $venue, $court, $request->user(),
                    CarbonImmutable::parse($data['date'].' '.$data['preview_start'], $result['timezone']),
                    (int) $data['preview_duration_minutes'], VenueBookingScopeEnum::WHOLE,
                    $baseline, $result['currency'] ?? $venue->bookingPolicyCurrency(), $data['coupon_code'] ?? null,
                );
                $result['price_preview'] = [
                    'total_minor' => $personal['final_amount_minor'] ?? $baseline,
                    'personal_discount' => $personal === null ? null : [
                        'original_amount_minor' => $baseline, 'reason' => $personal['reason'],
                    ],
                ];
            }
            foreach ($result['slots'] as &$slot) {
                unset($slot['pricing_breakdown'], $slot['base_price_minor']);
            }
            unset($slot);
            return response()->json($result)->header('Cache-Control', 'private, no-store');
        } catch (InvalidArgumentException|VenueBookingPolicyException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422)
                ->header('Cache-Control', 'no-store');
        }
    }
}
