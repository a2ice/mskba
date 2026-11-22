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

        $data = $request->validate([
            'after_id' => ['nullable', 'integer', 'min:0', 'prohibits:before_id'],
            'before_id' => ['nullable', 'integer', 'min:1', 'prohibits:after_id'],
            'include_roster' => ['sometimes', 'boolean'],
        ]);
        $after = isset($data['after_id']) ? (int) $data['after_id'] : null;
        $before = isset($data['before_id']) ? (int) $data['before_id'] : null;
        $report = $request->boolean('report');
        abort_if($report && ! $item->hasEnded(), 404);
        $canPost = $wall->canCommentOnEvent($item, $request->user(), $report);
        // Fetch one extra visible entry to determine whether an older page exists.
        // Preserve the existing forward cursor contract for the legacy poller.
        $entries = $wall->latestForEvent($item, $after === null ? 11 : 50, $after, $before, $report);
        $hasOlder = $after === null && $entries->count() > 10;
        if ($hasOlder) {
            $entries = $entries->slice(-10)->values();
        }

        return response()->json([
            'has_older' => $hasOlder,
            'entries' => $entries
                ->map(fn ($entry) => $this->present($entry, $item, $request, $wall, $canPost))->values(),
            'counts' => $wall->eventCounts($item),
            'can_post' => $canPost,
            'can_post_reason' => $report
                ? (! ($item->report_comments_enabled ?? true) ? 'Комментарии закрыты.' : 'Оценивать и комментировать могут только участники мероприятия.')
                : ($item->hasEnded() ? 'Мероприятие завершено. Обсуждение закрыто.' : (! ($item->discussion_enabled ?? true) ? 'Обсуждение закрыто.' : null)),
            ...($report ? $wall->reportFeedback($item, $request->user()) : []),
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
            $entry = $wall->postEventComment($item, $user, $data['body'], $data['client_request_id'], $request->boolean('report'));
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

    public function update(Request $request, string $event, int $entry, SocialWallService $wall): JsonResponse
    {
        $data = $request->validate([
            'body' => ['required', 'string', 'max:200'],
            'version' => ['required', 'integer', 'min:1'],
        ]);
        $item = $this->event($event);
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        try {
            $updated = $wall->editOwnEventComment($item, $user, $entry, $data['body'], (int) $data['version'], $request->boolean('report'));
        } catch (SocialActionRateLimitException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'retry_after' => $exception->retryAfter,
            ], 429)->header('Retry-After', (string) $exception->retryAfter);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['entry' => $this->present($updated, $item, $request, $wall, true)]);
    }

    public function destroy(Request $request, string $event, int $entry, SocialWallService $wall): JsonResponse
    {
        $item = $this->event($event);
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        try {
            $deleted = $wall->deleteOwnEventComment($item, $user, $entry, $request->boolean('report'));
        } catch (SocialActionRateLimitException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'retry_after' => $exception->retryAfter,
            ], 429)->header('Retry-After', (string) $exception->retryAfter);
        }
        return response()->json(['entry' => $this->present($deleted, $item, $request, $wall)]);
    }
    public function reaction(Request $request, string $event, SocialWallService $wall): JsonResponse
    {
        $data = $request->validate([
            'reaction' => ['present', 'nullable', 'in:like,dislike'],
            'body' => ['nullable', 'string', 'max:200'],
            'client_request_id' => ['required_with:body', 'nullable', 'uuid'],
        ]);
        $item = $this->event($event);
        $user = $request->user();
        abort_unless($user instanceof User && $wall->canViewEvent($item, $user), 403);

        try {
            $result = $wall->setEventReaction($item, $user, $data['reaction'], $data['body'] ?? null, $data['client_request_id'] ?? null);
            if (isset($result['entry'])) {
                $result['entry'] = $this->present($result['entry'], $item, $request, $wall);
            }

            return response()->json($result);
        } catch (SocialActionRateLimitException $exception) {
            return response()->json(['message' => $exception->getMessage(), 'retry_after' => $exception->retryAfter], 429)
                ->header('Retry-After', (string) $exception->retryAfter);
        }
    }

    private function present(
        \App\Modules\Social\Domain\Models\SocialWallEntry $entry,
        Event $event,
        Request $request,
        SocialWallService $wall,
        ?bool $canPost = null,
    ): array {
        $payload = $wall->present($entry);
        $viewer = $request->user()?->canonical();
        $report = $entry->scope_type === 'event_report';
        $canPost ??= $wall->canCommentOnEvent($event, $viewer, $report);
        $canDelete = $canPost && $viewer !== null
            && $payload['system'] === false
            && $payload['deleted'] === false
            && $entry->actor_user_id === $viewer->id;
        $payload['can_delete'] = $canDelete;
        $payload['delete_url'] = $canDelete
            ? route('events.wall.delete', ['event' => $event->routeIdentifier(), 'entry' => $entry->id] + ($report ? ['report' => 1] : [])) : null;

        $canEdit = $canDelete && ! $payload['hidden']
            && $canPost;
        $payload['can_edit'] = $canEdit;
        $payload['edit_url'] = $canEdit
            ? route('events.wall.update', ['event' => $event->routeIdentifier(), 'entry' => $entry->id] + ($report ? ['report' => 1] : [])) : null;

        return $payload;
    }

}
