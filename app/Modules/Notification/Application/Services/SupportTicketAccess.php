<?php

namespace App\Modules\Notification\Application\Services;

use App\Modules\Identity\Application\Services\UserOperationalPermissionChecker;
use App\Modules\Identity\Domain\Enums\UserOperationalPermissionEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Notification\Domain\Enums\UserNotificationStatusEnum;
use App\Modules\Notification\Domain\Models\RequestThread;
use App\Modules\Notification\Domain\Models\RequestThreadMembership;
use App\Modules\Notification\Domain\Models\SupportTicket;
use App\Modules\Notification\Domain\Models\UserNotification;
use App\Modules\Notification\Infrastructure\Broadcasting\ConversationChangedBroadcast;
use App\Modules\Notification\Infrastructure\Broadcasting\UserNotificationCountUpdatedBroadcast;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/** No role-derived bypass to a support conversation. */
final class SupportTicketAccess
{
    public function __construct(private readonly UserOperationalPermissionChecker $permissions) {}

    public function isAgent(User $user): bool
    {
        return $this->permissions->allows($user, UserOperationalPermissionEnum::HANDLE_SUPPORT_TICKETS);
    }

    public function afterId(User $user): ?int
    {
        if (! $this->isAgent($user)) {
            return null;
        }

        $user = $user->canonical();
        $cursor = DB::table('support_permission_cursors')->where('user_id', $user->id)
            ->value('after_ticket_id');
        if ($cursor === null && $user->hasSystemRole(UserSystemRoleEnum::SUPERADMIN)) {
            // Defense-in-depth for newly bootstrapped superadmins that did not
            // pass through the administrative role-change handler.
            $this->setGrantCursor($user);
            $cursor = DB::table('support_permission_cursors')->where('user_id', $user->id)
                ->value('after_ticket_id');
        }

        return $cursor === null ? null : (int) $cursor;
    }

    public function canRead(User $user, SupportTicket $ticket, ?RequestThread $thread = null): bool
    {
        $user = $user->canonical();
        if ($user->isBlocked() || $user->trashed()) {
            return false;
        }
        if ((int) $ticket->user_id === $user->id) {
            return true;
        }
        if (! $this->isAgent($user)) {
            return false;
        }
        $thread ??= $ticket->thread;
        if ($thread === null) {
            return false;
        }
        $membership = $thread->memberships()->where('user_id', $user->id)->first();
        if ($membership?->removed_at !== null) {
            return false;
        }
        if ($membership !== null) {
            return true;
        }
        $after = $this->afterId($user);

        return $after !== null && $ticket->id > $after;
    }

    public function canWrite(User $user, SupportTicket $ticket, ?RequestThread $thread = null): bool
    {
        return $ticket->status === 'open' && $this->canRead($user, $ticket, $thread);
    }

    /** EXISTS avoids multiplying tickets by memberships and grants. */
    public function visible(User $user): Builder
    {
        $user = $user->canonical();
        $cursor = $this->afterId($user);

        return SupportTicket::query()->where(function (Builder $q) use ($user, $cursor): void {
            $q->where('user_id', $user->id);
            if ($cursor === null) {
                return;
            }
            $q->orWhere('id', '>', $cursor)
                ->orWhereExists(function ($threads) use ($user): void {
                    $threads->selectRaw('1')->from('request_threads')
                        ->where('type', 'support')
                        ->whereColumn('subject_id', 'support_tickets.id')
                        ->whereExists(function ($members) use ($user): void {
                            $members->selectRaw('1')->from('request_thread_memberships')
                                ->whereColumn('request_thread_id', 'request_threads.id')
                                ->where('user_id', $user->id)->whereNull('removed_at');
                        });
                });
        })->when($cursor !== null, function (Builder $q) use ($user): void {
            // An explicit exclusion always wins over a broad new-ticket grant.
            $q->where(function (Builder $eligible) use ($user): void {
                $eligible->where('user_id', $user->id)->orWhereNotExists(function ($threads) use ($user): void {
                    $threads->selectRaw('1')->from('request_threads')
                        ->where('type', 'support')->whereColumn('subject_id', 'support_tickets.id')
                        ->whereExists(function ($members) use ($user): void {
                            $members->selectRaw('1')->from('request_thread_memberships')
                                ->whereColumn('request_thread_id', 'request_threads.id')
                                ->where('user_id', $user->id)->whereNotNull('removed_at');
                        });
                });
            });
        });
    }

    public function revokeForUser(User $user, User $actor): void
    {
        $userId = $user->canonical()->id;
        $threadIds = RequestThread::query()->where('type', 'support')
            ->where(function (Builder $query) use ($userId): void {
                $query->where('lead_user_id', $userId)
                    ->orWhereHas('memberships', fn ($m) => $m
                        ->where('user_id', $userId)->whereNull('removed_at'));
            })->pluck('id');
        // Revoke old toast previews too; an unread support notification can
        // otherwise reveal a message after its thread becomes inaccessible.
        // One DB subquery instead of materializing unbounded ticket IDs.
        $redacted = UserNotification::query()->where('user_id', $userId)
            ->whereNotNull('payload->support_ticket_id')
            ->whereIn('payload->support_ticket_id', SupportTicket::query()
                ->select('id')->where('user_id', '<>', $userId))
            ->update(['title' => 'Техподдержка MSKBA',
                'body' => 'Доступ к обращению прекращён.',
                'action_url' => route('account.notifications'), 'action_text' => null,
                'status' => UserNotificationStatusEnum::READ, 'read_at' => now()]);
        if ($redacted > 0) {
            app(UserNotificationCounterStore::class)->forget($userId);
        }
        if ($threadIds->isEmpty() && $redacted === 0) {
            return;
        }
        RequestThreadMembership::query()->whereIn('request_thread_id', $threadIds)
            ->where('user_id', $userId)->whereNull('removed_at')
            ->update(['removed_at' => now(), 'removed_by_user_id' => $actor->canonical()->id,
                'removal_reason' => 'permission_revoked']);
        RequestThread::query()->whereIn('id', $threadIds)->where('lead_user_id', $userId)
            ->update(['lead_user_id' => null]);
        DB::afterCommit(function () use ($threadIds, $redacted, $userId): void {
            if ($redacted > 0) {
                try {
                    UserNotificationCountUpdatedBroadcast::dispatch($userId,
                        app(UserNotificationCounterStore::class)->get($userId));
                } catch (\Throwable $exception) {
                    report($exception);
                }
            }
            foreach ($threadIds as $id) {
                try {
                    ConversationChangedBroadcast::dispatch('support', (int) $id, true);
                } catch (\Throwable $exception) {
                    report($exception);
                }
            }
        });
    }

    public function setGrantCursor(User $user): void
    {
        DB::table('support_permission_cursors')->updateOrInsert(
            ['user_id' => $user->canonical()->id],
            ['after_ticket_id' => SupportTicket::query()->max('id') ?? 0,
                'created_at' => now(), 'updated_at' => now()],
        );
    }
}
