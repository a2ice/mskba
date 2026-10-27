<?php

namespace App\Modules\PlayInterest\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\PlayInterest\Application\Services\PlayIntentManager;
use App\Modules\PlayInterest\Application\Services\RecommendPlayableSlots;
use App\Modules\PlayInterest\Application\Services\RecommendVenuesForIntent;
use App\Modules\PlayInterest\Domain\Models\PlayIntent;
use App\Modules\PlayInterest\Domain\Models\VenueSubscription;
use App\Modules\PlayInterest\Presentation\Http\Requests\SavePlayIntentRequest;
use App\Modules\Venue\Domain\Models\Amenity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PlayIntentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $this->viewer($request);
        $filters = $request->validate([
            'type' => ['sometimes', 'string', 'in:all,play,venue'],
            'status' => ['sometimes', 'string', 'in:all,active,paused,expired,cancelled,completed'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);
        $type = $filters['type'] ?? 'all';
        $status = $filters['status'] ?? 'active';
        $page = (int) ($filters['page'] ?? 1);
        $items = collect();

        if ($type === 'all' || $type === 'play') {
            $games = PlayIntent::query()->where('user_id', $user->id)->with('venue')->get();
            foreach ($games as $game) {
                $item = $this->serialize($game);
                if ($status === 'all' || $item['effective_status'] === $status) {
                    $item['updated_sort'] = $game->updated_at?->getTimestamp() ?? 0;
                    $items->push($item);
                }
            }
        }
        if ($type === 'all' || $type === 'venue') {
            $subscriptions = VenueSubscription::query()->where('user_id', $user->id)->with('venue')->get();
            foreach ($subscriptions as $subscription) {
                if ($status !== 'all' && $subscription->status !== $status) continue;
                $venue = $subscription->venue;
                $items->push([
                    'id' => $subscription->id,
                    'subscription_type' => 'venue',
                    'name' => 'Новости площадки',
                    'venue_id' => $subscription->venue_id,
                    'venue' => $venue ? ['id' => (int) $venue->id, 'name' => $venue->name,
                        'url' => route('venues.show', $venue->routeIdentifier())] : null,
                    'status' => $subscription->status,
                    'effective_status' => $subscription->status,
                    'notify_venue_news' => $subscription->status === 'active',
                    'delivery_channels' => $subscription->channels(),
                    'updated_sort' => $subscription->updated_at?->getTimestamp() ?? 0,
                ]);
            }
        }

        $items = $items->sortByDesc('updated_sort')->values();
        $total = $items->count();
        $perPage = 10;
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min($page, $lastPage);
        $slice = $items->forPage($page, $perPage)->map(function (array $item): array {
            unset($item['updated_sort']);
            return $item;
        })->values()->all();

        return response()->json([
            'data' => $slice,
            'meta' => [
                'current_page' => $page,
                'last_page' => $lastPage,
                'per_page' => $perPage,
                'total' => $total,
            ],
        ])->header('Cache-Control', 'private, no-store');
    }

    public function amenities(): JsonResponse
    {
        return response()->json([
            'data' => Amenity::query()->where('is_active', true)
                ->orderBy('sort_order')->orderBy('name')
                ->get(['id', 'name', 'alias', 'description', 'icon', 'applies_to'])
                ->toArray(),
        ]);
    }

    public function store(SavePlayIntentRequest $request, PlayIntentManager $manager): JsonResponse
    {
        return response()->json([
            'data' => $this->serialize($manager->save($this->viewer($request), $request->validated())),
        ], 201);
    }

    public function update(SavePlayIntentRequest $request, PlayIntent $playIntent, PlayIntentManager $manager): JsonResponse
    {
        $this->owned($request, $playIntent);

        return response()->json([
            'data' => $this->serialize($manager->save($this->viewer($request), $request->validated(), $playIntent)),
        ]);
    }

    public function pause(Request $request, PlayIntent $playIntent, PlayIntentManager $manager): JsonResponse
    {
        $this->owned($request, $playIntent);

        return response()->json([
            'data' => $this->serialize($manager->setPaused($this->viewer($request), $playIntent, true)),
        ]);
    }

    public function resume(Request $request, PlayIntent $playIntent, PlayIntentManager $manager): JsonResponse
    {
        $this->owned($request, $playIntent);

        return response()->json([
            'data' => $this->serialize($manager->setPaused($this->viewer($request), $playIntent, false)),
        ]);
    }

    public function restore(Request $request, PlayIntent $playIntent, PlayIntentManager $manager): JsonResponse
    {
        $this->owned($request, $playIntent);

        return response()->json([
            'data' => $this->serialize($manager->restoreExpired($this->viewer($request), $playIntent)),
        ]);
    }

    public function destroy(Request $request, PlayIntent $playIntent): JsonResponse
    {
        $this->owned($request, $playIntent);
        $playIntent->forceFill(['status' => 'cancelled'])->save();

        return response()->json(['message' => 'Подписка отменена.']);
    }

    public function recommendations(
        Request $request, PlayIntent $playIntent, RecommendVenuesForIntent $recommender,
    ): JsonResponse {
        $this->owned($request, $playIntent);

        // Expired subscriptions may preview geographically matching venues
        // before renewal. Never reactivate them or include them in audience counts.
        $preview = $playIntent;
        if ($playIntent->status === 'active' && $playIntent->expires_at?->isPast()) {
            $preview = clone $playIntent;
            $preview->expires_at = now()->addDay();
        }

        return response()->json([
            'data' => $recommender->handle($preview),
            'meta' => ['ranking' => 'geography_and_amenities_only', 'availability_verified' => false,
                'preview_only' => $preview !== $playIntent],
        ]);
    }

    public function slots(
        Request $request, PlayIntent $playIntent, RecommendPlayableSlots $recommender,
    ): JsonResponse {
        $this->owned($request, $playIntent);
        $params = $request->validate([
            'date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'time' => ['required', 'date_format:H:i'],
            'duration_minutes' => ['required', 'integer', 'between:30,240', 'multiple_of:30'],
            'tolerance_minutes' => ['sometimes', 'integer', 'between:0,120', 'multiple_of:30'],
        ]);

        return response()->json([
            'data' => $recommender->handle(
                $playIntent,
                $params['date'],
                $params['time'],
                (int) $params['duration_minutes'],
                (int) ($params['tolerance_minutes'] ?? 120),
            ),
            'meta' => [
                'ranking' => 'time_45_distance_35_amenities_20',
                'scope' => 'whole_court',
                'reservation_created' => false,
                'notice' => 'Доступность проверена на момент запроса. Перед бронированием нужна повторная проверка.',
            ],
        ]);
    }

    private function viewer(Request $request): User
    {
        /** @var User $user */
        $user = $request->user()->canonical();
        abort_if($user->isBlocked(), 403);

        return $user;
    }

    private function owned(Request $request, PlayIntent $intent): void
    {
        abort_unless($intent->user_id === $this->viewer($request)->id, 404);
    }

    /** @return array<string, mixed> */
    private function serialize(PlayIntent $intent): array
    {
        $intent->loadMissing('venue');
        $venue = $intent->venue;
        $data = $intent->only([
            'id', 'client_request_id', 'name', 'venue_id', 'include_similar_venues', 'center_latitude', 'center_longitude',
            'radius_km', 'excluded_venue_ids', 'cadence', 'weekdays', 'dates', 'windows', 'period_from', 'period_until',
            'formats', 'duration_minutes', 'access_mode', 'venue_kind', 'amenity_preferences',
            'notify_matches', 'notify_offers', 'status', 'show_on_venue', 'expires_at', 'created_at', 'updated_at',
        ]);
        $data['subscription_type'] = 'play';
        $data['effective_status'] = $intent->status === 'active' && $intent->expires_at?->isPast()
            ? 'expired' : $intent->status;
        // The owner can see which venue was chosen; this is not a public
        // directory of users interested in a venue.
        $data['venue'] = $venue === null ? null : [
            'id' => (int) $venue->id,
            'name' => $venue->name,
            'url' => route('venues.show', $venue->routeIdentifier()),
        ];

        return $data;
    }
}
