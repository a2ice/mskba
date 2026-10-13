<?php

namespace App\Modules\Notification\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Application\Services\ProfileChangeRequestNotificationService;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\ProfileChangeRequest;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Notification\Application\Services\ProfileRequestThreadAccess;
use App\Modules\Notification\Application\Services\ProfileThreadTimeline;
use App\Modules\Notification\Application\Services\RequestThreadMembershipManager;
use App\Modules\Notification\Application\UseCases\MarkConversationNotificationsReadHandler;
use App\Modules\Notification\Infrastructure\Broadcasting\ConversationChangedBroadcast;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

final class ProfileRequestThreadController extends Controller
{
    public function show(Request $request, ProfileChangeRequest $changeRequest,
        ProfileRequestThreadAccess $access, MarkConversationNotificationsReadHandler $read): Response
    {
        $user = $request->user()->canonical();
        $thread = $access->thread($changeRequest);
        $member = $access->membership($user, $thread);
        $canRead = $access->canRead($user, $changeRequest, $thread);
        abort_unless($canRead || $member !== null, 403);

        if (! $canRead) {
            // The tombstone is still a thread view: acknowledge its notification,
            // otherwise a generic exclusion alert would remain unread forever.
            $read->handle($user, profileChangeRequestId: $changeRequest->id);

            // Never load history or private request values for excluded reviewers.
            return ThemeResolver::page('account.profile-request-conversation', [
                'changeRequest' => $changeRequest, 'canRead' => false, 'thread' => $thread,
                'removedReason' => $member?->removal_reason === 'excluded'
                    ? 'Ты был исключён из участия в рассмотрении этой заявки.'
                    : 'Доступ к заявке прекращён: у тебя больше нет соответствующего разрешения.',
            ]);
        }

        $read->handle($user, profileChangeRequestId: $changeRequest->id);
        $thread->load(['lead.profile', 'memberships']);
        $participants = User::query()->whereIn('id', $thread->memberships
            ->filter(fn ($membership) => $membership->removed_at === null)
            ->pluck('user_id'))->with('profile')->get();
        $page = app(ProfileThreadTimeline::class)->page($changeRequest, $thread);
        $timeline = $page['entries'];

        return ThemeResolver::page('account.profile-request-conversation', [
            'changeRequest' => $changeRequest, 'canRead' => true, 'thread' => $thread,
            'timeline' => $timeline, 'nextBefore' => $page['next_before'], 'participants' => $participants,
            'canReview' => $access->isReviewer($user) && ! $access->isApplicant($user, $changeRequest),
            'isLead' => (int) $thread->lead_user_id === (int) $user->id,
            'isMember' => $member !== null && $member->removed_at === null,
            'canWrite' => $access->canWrite($user, $changeRequest, $thread),
            'canRescue' => $user->hasSystemRole(UserSystemRoleEnum::SUPERADMIN)
                && $thread->lead_user_id !== null && $thread->lead_user_id !== $user->id
                && ($thread->lead === null || ! $access->isReviewer($thread->lead)
                    || $access->membership($thread->lead, $thread)?->removed_at !== null),
            'viewer' => $user,
        ]);
    }

    public function history(Request $request, ProfileChangeRequest $changeRequest,
        ProfileRequestThreadAccess $access, ProfileThreadTimeline $timeline): JsonResponse
    {
        $user = $request->user()->canonical();
        $thread = $access->thread($changeRequest);
        abort_unless($access->canRead($user, $changeRequest, $thread), 403);
        $before = $request->query('before');
        abort_unless($before === null || is_string($before), 422);
        $page = $timeline->page($changeRequest, $thread, $before);

        return response()->json([
            'html' => $page['entries']->map(fn ($entry) => view('theme::pages.account.partials.chat-bubble', [
                'entry' => $entry, 'viewer' => $user,
            ])->render())->implode(''),
            'next_before' => $page['next_before'],
        ]);
    }

    /** Acknowledge only the current thread after server-side access validation. */
    public function read(Request $request, ProfileChangeRequest $changeRequest,
        ProfileRequestThreadAccess $access, MarkConversationNotificationsReadHandler $notifications): JsonResponse
    {
        $user = $request->user()->canonical();
        $thread = $access->thread($changeRequest);
        abort_unless($access->canRead($user, $changeRequest, $thread), 403);

        return response()->json([
            'unread_count' => $notifications->handle($user, profileChangeRequestId: $changeRequest->id),
        ]);
    }

    public function rescue(Request $request, ProfileChangeRequest $changeRequest, RequestThreadMembershipManager $members,
        ProfileChangeRequestNotificationService $notifications): RedirectResponse
    {
        $members->rescue($request->user(), $changeRequest);
        $notifications->leadRescued($changeRequest);
        $this->broadcast($changeRequest, true);

        return $this->back($changeRequest);
    }

    public function claim(Request $request, ProfileChangeRequest $changeRequest, RequestThreadMembershipManager $members,
        ProfileChangeRequestNotificationService $notifications): RedirectResponse
    {
        if ($members->claim($request->user(), $changeRequest)) {
            $notifications->claimed($changeRequest);
            $this->broadcast($changeRequest, true);
        }

        return $this->back($changeRequest);
    }

    public function candidates(Request $request, ProfileChangeRequest $changeRequest,
        ProfileRequestThreadAccess $access): JsonResponse
    {
        $user = $request->user()->canonical();
        $thread = $access->thread($changeRequest);
        abort_unless($thread->lead_user_id === $user->id && $access->canRead($user, $changeRequest, $thread)
            && $changeRequest->status === ProfileChangeRequest::PENDING, 403);
        $q = mb_strtolower(trim((string) $request->query('q', '')));
        if (mb_strlen($q) < 2 || mb_strlen($q) > 60) {
            return response()->json(['candidates' => []]);
        }
        $users = User::query()->where('status', UserStatusEnum::CONFIRMED->value)
            ->whereIn('system_role', [UserSystemRoleEnum::ADMIN->value,
                UserSystemRoleEnum::SUPERADMIN->value])
            ->where('username', 'like', $q.'%')
            ->whereKeyNot($user->id)->whereKeyNot($changeRequest->user_id)
            ->whereNotExists(function ($members) use ($thread): void {
                $members->selectRaw('1')->from('request_thread_memberships')
                    ->where('request_thread_id', $thread->id)
                    ->whereNull('removed_at')->whereColumn('user_id', 'users.id');
            })->orderBy('username')->limit(20)->get();

        return response()->json(['candidates' => $users->filter(fn ($candidate) => $access->isReviewer($candidate))
            ->take(10)->map(fn ($candidate) => ['id' => $candidate->id, 'label' => $candidate->username])->values()]);
    }

    public function invite(Request $request, ProfileChangeRequest $changeRequest, RequestThreadMembershipManager $members,
        ProfileChangeRequestNotificationService $notifications): RedirectResponse
    {
        $data = $request->validate(['user_id' => ['required', 'integer', 'exists:users,id']]);
        $target = User::query()->findOrFail($data['user_id']);
        $members->invite($request->user(), $changeRequest, $target);
        $notifications->invited($changeRequest, $target);
        $this->broadcast($changeRequest, true);

        return $this->back($changeRequest);
    }

    public function transfer(Request $request, ProfileChangeRequest $changeRequest, RequestThreadMembershipManager $members,
        ProfileChangeRequestNotificationService $notifications): RedirectResponse
    {
        $data = $request->validate(['user_id' => ['required', 'integer', 'exists:users,id']]);
        $target = User::query()->findOrFail($data['user_id']);
        $members->transfer($request->user(), $changeRequest, $target);
        $notifications->leadTransferred($changeRequest, $target);
        $this->broadcast($changeRequest, true);

        return $this->back($changeRequest);
    }

    public function remove(Request $request, ProfileChangeRequest $changeRequest, RequestThreadMembershipManager $members,
        ProfileChangeRequestNotificationService $notifications): RedirectResponse
    {
        $data = $request->validate(['user_id' => ['required', 'integer', 'exists:users,id']]);
        $target = User::query()->findOrFail($data['user_id']);
        $members->remove($request->user(), $changeRequest, $target);
        $notifications->excluded($changeRequest, $target);
        $this->broadcast($changeRequest, true);

        return $this->back($changeRequest);
    }

    public function message(Request $request, ProfileChangeRequest $changeRequest,
        ProfileRequestThreadAccess $access, ProfileChangeRequestNotificationService $notifications): RedirectResponse|JsonResponse
    {
        $data = $request->validate(['body' => ['required', 'string', 'max:2000']]);
        $user = $request->user()->canonical();
        DB::transaction(function () use ($access, $changeRequest, $user, $data): void {
            $lockedUser = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $locked = ProfileChangeRequest::query()->whereKey($changeRequest->id)->lockForUpdate()->firstOrFail();
            $thread = $access->thread($locked);
            abort_unless($access->canWrite($lockedUser, $locked, $thread), 403);
            $thread->messages()->create(['sender_user_id' => $user->id, 'body' => trim($data['body'])]);
        });
        $notifications->messageSent($changeRequest, $user);
        $this->broadcast($changeRequest);

        return $request->expectsJson()
            ? response()->json(['sent' => true], 201)
            : $this->back($changeRequest);
    }

    private function broadcast(ProfileChangeRequest $request, bool $accessChanged = false): void
    {
        $threadId = $request->thread?->id;
        if ($threadId !== null) {
            try {
                ConversationChangedBroadcast::dispatch('profile', $threadId, $accessChanged);
            } catch (\Throwable $exception) {
                report($exception);
            }
        }
    }

    private function back(ProfileChangeRequest $request): RedirectResponse
    {
        return redirect()->route('account.notifications.threads.profile', $request->id);
    }
}
