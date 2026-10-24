<?php

namespace App\Modules\Favorites\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Favorites\Application\Services\FavoriteTargetRegistry;
use App\Modules\Favorites\Application\Services\FavoritesManager;
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
            'type' => $type,
            'id' => $id,
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
