<?php

namespace App\Modules\Notification\Application\Services;

use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\ProfileChangeRequest;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Notification\Domain\Enums\UserNotificationStatusEnum;
use App\Modules\Notification\Domain\Models\RequestThread;
use App\Modules\Notification\Domain\Models\RequestThreadMembership;
use App\Modules\Notification\Domain\Models\UserNotification;
use App\Modules\Notification\Infrastructure\Broadcasting\ConversationChangedBroadcast;
use App\Modules\Notification\Infrastructure\Broadcasting\UserNotificationCountUpdatedBroadcast;
use Illuminate\Support\Facades\DB;

/** Transactional ownership and membership for all future request thread adapters. */
final class RequestThreadMembershipManager
{
    public function __construct(private readonly ProfileRequestThreadAccess $access) {}

    public function claim(User $actor, ProfileChangeRequest $request): bool
    {
        $actor = $actor->canonical();

        return DB::transaction(function () use ($actor, $request): bool {
            $actor = User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $thread = RequestThread::query()->where('type', 'profile_change')
                ->where('subject_id', $request->id)->lockForUpdate()->firstOrFail();
            abort_unless($request->status === ProfileChangeRequest::PENDING && ! $this->access->isApplicant($actor, $request)
                && $this->access->canRead($actor, $request, $thread), 403);
            $previous = $this->access->membership($actor, $thread);
            abort_if($previous?->removed_at !== null, 403, 'Повторный доступ возможен только по приглашению главного.');
            if ($previous !== null && $thread->lead_user_id !== null) {
                return false; // Repeated POST is idempotent, including its audit trail.
            }
            if ($previous === null) {
                $thread->memberships()->create(['user_id' => $actor->id, 'joined_at' => now()]);
            }
            if ($thread->lead_user_id === null) {
                $thread->update(['lead_user_id' => $actor->id]);
                $this->event($thread, $actor, 'lead_assigned');
            } else {
                $this->event($thread, $actor, 'joined');
            }

            return true;
        });
    }

    public function invite(User $actor, ProfileChangeRequest $request, User $target): void
    {
        $this->modify($actor, $request, function (RequestThread $thread, User $actor) use ($target, $request): void {
            $target = $target->canonical();
            abort_if($actor->id === $target->id || $target->id === (int) $request->user_id, 422);
            abort_unless($this->access->isReviewer($target), 422, 'У участника отсутствует право рассмотрения заявок этого типа.');
            $member = $this->access->membership($target, $thread);
            abort_if($member !== null && $member->removed_at === null, 422, 'Участник уже приглашён.');
            if ($member !== null) {
                $member->update(['removed_at' => null, 'removed_by_user_id' => null,
                    'removal_reason' => null, 'invited_by_user_id' => $actor->id, 'joined_at' => now()]);
            } else {
                $thread->memberships()->create(['user_id' => $target->id, 'joined_at' => now(), 'invited_by_user_id' => $actor->id]);
            }
            $this->event($thread, $actor, 'invited', $target);
        });
    }

    public function transfer(User $actor, ProfileChangeRequest $request, User $target): void
    {
        $this->modify($actor, $request, function (RequestThread $thread, User $actor) use ($target): void {
            $target = $target->canonical();
            $member = $this->access->membership($target, $thread);
            abort_unless($member !== null && $member->removed_at === null && $this->access->isReviewer($target), 422);
            abort_if($target->id === $actor->id, 422);
            $thread->update(['lead_user_id' => $target->id]);
            $this->event($thread, $actor, 'lead_transferred', $target);
        });
    }

    public function remove(User $actor, ProfileChangeRequest $request, User $target): void
    {
        $this->modify($actor, $request, function (RequestThread $thread, User $actor) use ($target, $request): void {
            $target = $target->canonical();
            abort_if($target->id === $actor->id || $target->id === (int) $request->user_id, 422);
            $member = $this->access->membership($target, $thread);
            abort_unless($member !== null && $member->removed_at === null, 422);
            $member->update(['removed_at' => now(), 'removed_by_user_id' => $actor->id,
                'removal_reason' => 'excluded']);
            $this->event($thread, $actor, 'removed', $target);
            $this->clearUnread($target, $request);
        });
    }

    /** Emergency ownership repair; no right to override an active lead or an exclusion. */
    public function rescue(User $actor, ProfileChangeRequest $request): void
    {
        $actor = $actor->canonical();
        abort_unless($actor->isConfirmed()
            && $actor->hasSystemRole(UserSystemRoleEnum::SUPERADMIN)
            && $this->access->isReviewer($actor), 403);
        DB::transaction(function () use ($actor, $request): void {
            $thread = RequestThread::query()->where('type', 'profile_change')
                ->where('subject_id', $request->id)->lockForUpdate()->firstOrFail();
            abort_unless($request->status === ProfileChangeRequest::PENDING && $thread->lead_user_id !== null, 422);
            $lead = User::query()->find($thread->lead_user_id);
            $leadMember = $lead ? $this->access->membership($lead, $thread) : null;
            abort_if($lead !== null && $this->access->isReviewer($lead)
                && $leadMember !== null && $leadMember->removed_at === null, 403,
                'Действующий главный администратор сохраняет полномочия.');
            $membership = $this->access->membership($actor, $thread);
            abort_if($membership?->removed_at !== null, 403);
            if ($membership === null) {
                $thread->memberships()->create(['user_id' => $actor->id, 'joined_at' => now()]);
            }
            $thread->update(['lead_user_id' => $actor->id]);
            $this->event($thread, $actor, 'lead_rescued');
        });
    }

    public function revokeForUser(User $target, User $actor): void
    {
        $targetId = $target->canonical()->id;
        $actorId = $actor->canonical()->id;
        // One indexed subquery + eager load instead of N queries and large JOINs.
        $memberships = RequestThreadMembership::query()
            ->where('user_id', $targetId)->whereNull('removed_at')
            ->whereIn('request_thread_id', RequestThread::query()->select('id')->where('type', 'profile_change'))
            ->with('thread')->get();
        if ($memberships->isEmpty()) {
            return;
        }
        $now = now();
        RequestThreadMembership::query()->whereIn('id', $memberships->pluck('id'))
            ->update(['removed_at' => $now, 'removed_by_user_id' => $actorId,
                'removal_reason' => 'permission_revoked']);
        DB::table('request_thread_events')->insert(
            $memberships->map(fn ($m) => [
                'request_thread_id' => $m->request_thread_id, 'actor_user_id' => $actorId,
                'target_user_id' => $targetId, 'action' => 'permission_revoked',
                'created_at' => $now,
            ])->all()
        );
        $requestIds = $memberships->pluck('thread.subject_id')->filter()->values();
        $changed = UserNotification::query()
            ->where('user_id', $targetId)
            ->whereIn('payload->profile_change_request_id', $requestIds)
            ->where('status', UserNotificationStatusEnum::NEW)
            ->update(['status' => UserNotificationStatusEnum::READ,
                'read_at' => $now]);
        if ($changed > 0) {
            $this->broadcastUnreadCount($targetId);
        }
        // Notify currently subscribed browsers only after permission transaction commits.
        $threadIds = $memberships->pluck('request_thread_id')->unique()->values()->all();
        DB::afterCommit(function () use ($threadIds): void {
            foreach ($threadIds as $id) {
                try {
                    ConversationChangedBroadcast::dispatch('profile', (int) $id, true);
                } catch (\Throwable $exception) {
                    report($exception);
                }
            }
        });
    }

    private function modify(User $actor, ProfileChangeRequest $request, \Closure $callback): void
    {
        $actor = $actor->canonical();
        DB::transaction(function () use ($actor, $request, $callback): void {
            $actor = User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $thread = RequestThread::query()->where('type', 'profile_change')
                ->where('subject_id', $request->id)->lockForUpdate()->firstOrFail();
            abort_unless($thread->lead_user_id === $actor->id && $this->access->canRead($actor, $request, $thread)
                && $request->status === ProfileChangeRequest::PENDING, 403);
            $callback($thread, $actor);
        });
    }

    private function clearUnread(User $target, ProfileChangeRequest $request): void
    {
        $changed = UserNotification::query()
            ->where('user_id', $target->canonical()->id)
            ->where('payload->profile_change_request_id', $request->id)
            ->where('status', UserNotificationStatusEnum::NEW)
            ->update(['status' => UserNotificationStatusEnum::READ,
                'read_at' => now()]);
        if ($changed > 0) {
            $this->broadcastUnreadCount($target->canonical()->id);
        }
    }

    private function broadcastUnreadCount(int $userId): void
    {
        $counts = app(UserNotificationCounterStore::class);
        $counts->forget($userId);
        try {
            UserNotificationCountUpdatedBroadcast::dispatch(
                $userId, $counts->get($userId),
            );
        } catch (\Throwable $e) {
            report($e);
        }
    }

    private function event(RequestThread $thread, User $actor, string $action, ?User $target = null): void
    {
        $thread->events()->create(['actor_user_id' => $actor->id,
            'target_user_id' => $target?->id, 'action' => $action, 'created_at' => now()]);
    }
}
