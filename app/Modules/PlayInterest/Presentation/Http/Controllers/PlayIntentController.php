<?php

namespace App\Modules\PlayInterest\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\PlayInterest\Application\Services\PlayIntentManager;
use App\Modules\PlayInterest\Application\Services\RecommendVenuesForIntent;
use App\Modules\PlayInterest\Domain\Models\PlayIntent;
use App\Modules\PlayInterest\Presentation\Http\Requests\SavePlayIntentRequest;
use App\Modules\Venue\Domain\Models\Amenity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PlayIntentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $this->viewer($request);

        return response()->json([
            'data' => PlayIntent::query()
                ->where('user_id', $user->id)
                ->orderByDesc('updated_at')
                ->orderByDesc('id')
                ->limit(100)
                ->get()
                ->map(fn (PlayIntent $intent): array => $this->serialize($intent))
                ->values()
                ->all(),
        ]);
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

    public function destroy(Request $request, PlayIntent $playIntent): JsonResponse
    {
        $this->owned($request, $playIntent);
        $playIntent->forceFill(['status' => 'cancelled'])->save();

        return response()->json(['message' => 'Интерес отменён.']);
    }

    public function recommendations(
        Request $request, PlayIntent $playIntent, RecommendVenuesForIntent $recommender,
    ): JsonResponse {
        $this->owned($request, $playIntent);

        return response()->json([
            'data' => $recommender->handle($playIntent),
            'meta' => ['ranking' => 'geography_and_amenities_only', 'availability_verified' => false],
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
        $data = $intent->only([
            'id', 'venue_id', 'include_similar_venues', 'center_latitude', 'center_longitude',
            'radius_km', 'cadence', 'weekdays', 'dates', 'windows', 'period_from', 'period_until',
            'formats', 'duration_minutes', 'access_mode', 'amenity_preferences',
            'notify_matches', 'notify_offers', 'status', 'expires_at', 'created_at', 'updated_at',
        ]);
        $data['effective_status'] = $intent->status === 'active' && $intent->expires_at?->isPast()
            ? 'expired' : $intent->status;

        return $data;
    }
}
