<?php

namespace App\Modules\Favorites\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Favorites\Application\Services\FavoriteTargetRegistry;
use App\Modules\Favorites\Application\Services\FavoritesManager;
use App\Modules\Favorites\Domain\Models\Favorite;
use App\Modules\PlayInterest\Application\Services\FavoritePlayIntentService;
use App\Modules\PlayInterest\Domain\Models\PlayIntent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class FavoritesController extends Controller
{
    public function status(
        Request $request,
        FavoritesManager $favorites,
    ): JsonResponse {
        $validated = $request->validate([
            'type' => ['required', Rule::in(array_keys(FavoriteTargetRegistry::TYPES))],
            'id' => ['required', 'integer', 'min:1'],
        ]);

        return response()->json([
            'state' => $favorites->status($request, $validated['type'], (int) $validated['id']),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function venueStatuses(Request $request, FavoritesManager $favorites): JsonResponse
    {
        $input = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['required', 'integer', 'min:1'],
        ]);
        $ids = array_values(array_unique(array_map('intval', $input['ids'])));
        $items = $favorites->owned($request)->where('entity_type', 'venue')
            ->whereIn('entity_id', $ids)
            ->get(['entity_id', 'trashed_at', 'notify_upcoming_events'])
            ->mapWithKeys(fn ($item) => [
                (string) $item->entity_id => [
                    'state' => $item->trashed_at === null ? 'active' : 'trashed',
                    'notify_upcoming_events' => $item->notify_upcoming_events,
                ],
            ])->all();

        $subscriptions = $request->user() ? PlayIntent::query()
            ->where('user_id', $request->user()->canonical()->id)
            ->where('status', 'active')->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))->whereIn('venue_id', $ids)
            ->pluck('id', 'venue_id')->all() : [];
        foreach ($ids as $id) {
            $key = (string) $id;
            $items[$key] = [...($items[$key] ?? ['state' => 'none', 'notify_upcoming_events' => false]),
                'subscribed' => isset($subscriptions[$id]),
                'subscription_id' => $subscriptions[$id] ?? null];
        }

        $counts = Favorite::query()->where('entity_type', 'venue')->whereNull('trashed_at')
            ->whereIn('entity_id', $ids)->selectRaw('entity_id, COUNT(*) AS total')
            ->groupBy('entity_id')->pluck('total', 'entity_id');

        return response()->json(['data' => $items, 'counts' => $counts])
            ->header('Cache-Control', 'private, no-store');
    }

    public function toggle(
        Request $request,
        FavoritesManager $favorites,
        FavoriteTargetRegistry $registry,
    ): JsonResponse {
        $validated = $request->validate([
            'type' => ['required', Rule::in(array_keys(FavoriteTargetRegistry::TYPES))],
            'id' => ['required', 'integer', 'min:1'],
        ]);
        $type = $validated['type'];
        $id = (int) $validated['id'];
        $current = $favorites->status($request, $type, $id);

        // Removing a now-inaccessible old entry is always allowed;
        // adding/restoring it must not expose a private or blocked object.
        if ($current !== 'active') {
            abort_unless($registry->visible($type, $id) !== null, 404);
        }

        $favorite = $favorites->toggle($request, $type, $id);

        return response()->json([
            'state' => $favorite->trashed_at === null ? 'active' : 'trashed',
            'notify_upcoming_events' => $favorite->notify_upcoming_events,
            'count' => $type === 'venue' ? Favorite::query()
                ->where('entity_type', 'venue')->where('entity_id', $id)
                ->whereNull('trashed_at')->count() : null,
            'type' => $type,
            'id' => $id,
        ])->header('Cache-Control', 'private, no-store');
    }

    public function updatePreferences(
        Request $request,
        FavoritesManager $favorites,
        FavoritePlayIntentService $subscriptions,
    ): JsonResponse {
        abort_unless($request->user() !== null, 401);
        $validated = $request->validate([
            'venue_id' => ['required', 'integer', 'min:1'],
            'notify_upcoming_events' => ['required', 'boolean'],
        ]);
        $venueId = (int) $validated['venue_id'];
        $enabled = (bool) $validated['notify_upcoming_events'];
        // The favorite-preferences endpoint is only usable for an existing,
        // active favorite. Game-subscription editing uses the independent
        // VenueSubscriptionManager and does not require a favorite.
        $favorite = $favorites->owned($request)->where('entity_type', 'venue')
            ->where('entity_id', $venueId)->whereNull('trashed_at')->first();
        abort_unless($favorite !== null, 404);

        $subscription = $enabled
            ? $subscriptions->ensure($request->user(), $venueId)
            : $subscriptions->disableVenueNews($request->user(), $venueId);
        $favorite->forceFill(['notify_upcoming_events' => $enabled])->save();

        return response()->json([
            'state' => $favorite ? 'active' : 'none',
            'notify_upcoming_events' => $enabled,
            'subscribed' => $subscription?->status === 'active',
            'subscription_id' => $subscription?->id,
        ])->header('Cache-Control', 'private, no-store');
    }

    public function index(
        Request $request,
        FavoritesManager $favorites,
        FavoriteTargetRegistry $registry,
    ): JsonResponse {
        abort_unless($request->user() !== null, 401);
        $validated = $request->validate([
            'type' => ['sometimes', Rule::in(['all', ...array_keys(FavoriteTargetRegistry::TYPES)])],
            'state' => ['sometimes', Rule::in(['active', 'trashed'])],
        ]);
        $state = $validated['state'] ?? 'active';
        $type = $validated['type'] ?? 'all';
        $query = $favorites->owned($request);
        if ($type !== 'all') {
            $query->where('entity_type', $type);
        }
        if ($state === 'trashed') {
            $query->whereNotNull('trashed_at');
        } else {
            $query->whereNull('trashed_at');
        }

        $items = $query->orderByDesc('updated_at')->orderByDesc('id')
            ->limit(200)->get()->map(function ($item) use ($registry): array {
                return [
                    'id' => $item->id,
                    'type' => $item->entity_type,
                    'type_label' => FavoriteTargetRegistry::TYPES[$item->entity_type] ?? 'Другое',
                    'entity_id' => $item->entity_id,
                    'state' => $item->trashed_at === null ? 'active' : 'trashed',
                    'notify_upcoming_events' => $item->notify_upcoming_events,
                    ...$registry->describe($item->entity_type, $item->entity_id),
                ];
            })->values()->all();

        return response()->json([
            'data' => $items,
            'types' => FavoriteTargetRegistry::TYPES,
            'state' => $state,
            'type' => $type,
        ])->header('Cache-Control', 'private, no-store');
    }
}
