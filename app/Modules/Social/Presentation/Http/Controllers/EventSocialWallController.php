<?php

namespace App\Modules\Social\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Event\Application\Services\EventPreviewRoster;
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

    public function index(Request $request, string $event, SocialWallService $wall): JsonResponse
    {
        $item = $this->event($event);
        abort_unless($wall->canViewEvent($item, $request->user()), 404);

        $data = $request->validate(['after_id' => ['nullable', 'integer', 'min:0'], 'include_roster' => ['sometimes', 'boolean']]);
        $after = isset($data['after_id']) ? (int) $data['after_id'] : null;
        return response()->json([
            'entries' => $wall->latestForEvent($item, 50, $after)
                ->map(fn ($entry) => $this->present($entry, $item, $request, $wall))->values(),
            'counts' => $wall->eventCounts($item),
            'can_post' => $wall->canCommentOnEvent($item, $request->user()),
            ...($request->boolean('include_roster') ? ['roster' => app(EventPreviewRoster::class)->forEvent($item, $request->user())] : []),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request, string $event, SocialWallService $wall): JsonResponse
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

        return response()->json(['entry' => $this->present($entry, $item, $request, $wall)], 201);
    }

    public function destroy(Request $request, string $event, int $entry, SocialWallService $wall): JsonResponse
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
        return response()->json(['entry' => $this->present($deleted, $item, $request, $wall)]);
    }
    private function present(
        \App\Modules\Social\Domain\Models\SocialWallEntry $entry,
        Event $event,
        Request $request,
        SocialWallService $wall,
    ): array {
        $payload = $wall->present($entry);
        $viewer = $request->user()?->canonical();
        $canDelete = $viewer !== null
            && $payload['system'] === false
            && $payload['deleted'] === false
            && $entry->actor_user_id === $viewer->id;
        $payload['can_delete'] = $canDelete;
        $payload['delete_url'] = $canDelete
            ? route('events.wall.delete', [$event->routeIdentifier(), $entry->id]) : null;

        return $payload;
    }

}
