<?php

namespace App\Modules\Social\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Social\Application\Exceptions\SocialActionRateLimitException;
use App\Modules\Social\Application\Services\SocialWallService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

final class EventSocialWallController extends Controller
{
    private function event(string $id): Event
    {
        return Event::query()->whereRouteIdentifier($id)->firstOrFail();
    }

    public function index(string $event, Request $request, SocialWallService $wall): JsonResponse
    {
        $item = $this->event($event);
        abort_unless($wall->canViewEvent($item, $request->user()), 404);

        $data = $request->validate(['after_id' => ['nullable', 'integer', 'min:0']]);
        $after = isset($data['after_id']) ? (int) $data['after_id'] : null;
        return response()->json([
            'entries' => $wall->latestForEvent($item, 50, $after)
                ->map(fn ($entry) => $wall->present($entry))->values(),
            'counts' => $wall->eventCounts($item),
            'can_post' => $wall->canCommentOnEvent($item, $request->user()),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function store(string $event, Request $request, SocialWallService $wall): JsonResponse
    {
        $data = $request->validate([
            'body' => ['required', 'string', 'max:200'],
            'client_request_id' => ['required', 'uuid'],
        ]);
        $item = $this->event($event);
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        try {
            $entry = $wall->postEventComment($item, $user, $data['body'], $data['client_request_id']);
        } catch (SocialActionRateLimitException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'retry_after' => $exception->retryAfter,
            ], 429)->header('Retry-After', (string) $exception->retryAfter);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['entry' => $wall->present($entry)], 201);
    }

    public function destroy(string $event, int $entry, Request $request, SocialWallService $wall): JsonResponse
    {
        $item = $this->event($event);
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        try {
            $deleted = $wall->deleteOwnEventComment($item, $user, $entry);
        } catch (SocialActionRateLimitException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'retry_after' => $exception->retryAfter,
            ], 429)->header('Retry-After', (string) $exception->retryAfter);
        }
        return response()->json(['entry' => $wall->present($deleted)]);
    }
}
