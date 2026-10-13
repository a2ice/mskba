<?php

namespace App\Modules\Notification\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Notification\Application\Services\SystemNotificationTimeline;
use App\Modules\Notification\Application\UseCases\BuildTeamRequestTimelineHandler;
use App\Modules\Notification\Application\UseCases\MarkConversationNotificationsReadHandler;
use App\Modules\Team\Application\Services\TeamManagementAccess;
use App\Modules\Team\Application\Services\TeamNotificationService;
use App\Modules\Team\Domain\Enums\TeamJoinRequestStatusEnum;
use App\Modules\Team\Domain\Enums\TeamPermissionEnum;
use App\Modules\Team\Domain\Models\TeamJoinRequest;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

final class AccountConversationsController extends Controller
{
    private function authorizeTeamRequest(Request $request, TeamJoinRequest $joinRequest,
        CurrentActorResolver $actors, TeamManagementAccess $permissions): array
    {
        $user = $request->user()->canonical();
        $joinRequest->loadMissing(['team', 'user.profile', 'messages.sender.profile']);
        abort_if($joinRequest->team === null, 404);
        $actor = $actors->resolveForRequest($request);
        $isApplicant = in_array((int) $joinRequest->user_id, $user->identityIds(), true);
        $isManager = $actor !== null && $permissions->allows(
            $joinRequest->team, $actor, TeamPermissionEnum::MANAGE_JOIN_REQUESTS
        );
        abort_unless($isApplicant || $isManager, 403);

        return [$user, $isApplicant];
    }

    public function team(Request $request, TeamJoinRequest $joinRequest,
        CurrentActorResolver $actors, TeamManagementAccess $permissions,
        TeamNotificationService $notifications,
        BuildTeamRequestTimelineHandler $timeline): Response
    {
        [$user] = $this->authorizeTeamRequest($request, $joinRequest, $actors, $permissions);
        $canWrite = ! in_array($joinRequest->status, [
            TeamJoinRequestStatusEnum::ACCEPTED, TeamJoinRequestStatusEnum::BLOCKED,
        ], true);

        $members = $notifications->joinRequestManagers($joinRequest->team)
            ->push((int) $joinRequest->user_id)->unique()->values();
        $participants = User::query()
            ->whereIn('id', $members)->with('profile')->get()
            ->filter(fn ($participant): bool => ! $user->isSameIdentity($participant));

        return ThemeResolver::page('account.team-request-conversation', [
            'joinRequest' => $joinRequest,
            // Reverse only for presentation: preserve canonical chronological
            // timeline construction and its original application event.
            'timeline' => $timeline->handle($joinRequest, $user)->reverse()->values(),
            'canWrite' => $canWrite,
            'viewer' => $user,
            'participants' => $participants,
        ]);
    }

    public function sendTeam(Request $request, TeamJoinRequest $joinRequest,
        CurrentActorResolver $actors, TeamManagementAccess $permissions,
        TeamNotificationService $notifications): JsonResponse|RedirectResponse
    {
        [$user, $isApplicant] = $this->authorizeTeamRequest($request, $joinRequest, $actors, $permissions);
        $data = $request->validate(['body' => ['required', 'string', 'max:2000']]);
        abort_if($user->isBlocked() || $user->trashed(), 403);

        DB::transaction(function () use ($joinRequest, $user, $isApplicant, $data): void {
            $locked = TeamJoinRequest::query()->whereKey($joinRequest->id)->lockForUpdate()->firstOrFail();
            abort_if(in_array($locked->status, [
                TeamJoinRequestStatusEnum::ACCEPTED, TeamJoinRequestStatusEnum::BLOCKED,
            ], true), 422, 'Переписка по этой заявке закрыта.');
            $locked->messages()->create([
                'sender_user_id' => $user->id,
                'body' => trim($data['body']),
            ]);
            if ($locked->status === TeamJoinRequestStatusEnum::PENDING && ! $isApplicant) {
                $locked->update(['status' => TeamJoinRequestStatusEnum::AWAITING_RESPONSE]);
            } elseif ($locked->status === TeamJoinRequestStatusEnum::AWAITING_RESPONSE && $isApplicant) {
                $locked->update(['status' => TeamJoinRequestStatusEnum::PENDING]);
            }
            // A rejected request remains rejected; it never silently becomes pending.
        });

        $notifications->joinRequestMessageSent($joinRequest->team, $joinRequest, ! $isApplicant);

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Сообщение отправлено.']);
        }

        return redirect()->route('account.notifications.threads.team', $joinRequest->id)
            ->with('status', 'Сообщение отправлено.');
    }

    public function historySystem(Request $request, SystemNotificationTimeline $timeline): JsonResponse
    {
        $before = $request->query('before');
        abort_unless($before === null || (is_string($before) && ctype_digit($before) && (int) $before > 0), 422);
        $page = $timeline->page($request->user(), $before !== null ? (int) $before : null);

        return response()->json([
            'html' => $page['entries']->map(fn ($notification) => view('theme::pages.account.partials.system-notification-bubble', [
                'notification' => $notification,
            ])->render())->implode(''),
            'next_before' => $page['next_before'],
        ]);
    }

    public function readSystem(Request $request, MarkConversationNotificationsReadHandler $read): JsonResponse
    {
        return response()->json(['unread_count' => $read->handle($request->user()->canonical())]);
    }

    public function readTeam(Request $request, TeamJoinRequest $joinRequest,
        CurrentActorResolver $actors, TeamManagementAccess $permissions,
        MarkConversationNotificationsReadHandler $read): JsonResponse
    {
        [$user] = $this->authorizeTeamRequest($request, $joinRequest, $actors, $permissions);

        return response()->json(['unread_count' => $read->handle($user, $joinRequest->id)]);
    }
}
