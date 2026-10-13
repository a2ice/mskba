<?php

namespace App\Modules\Notification\Application\Services;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Notification\Domain\Models\DirectConversation;
use Illuminate\Support\Facades\DB;

/** Personal direct-message blacklist, independent of account-level moderation. */
final class DirectMessageBlockService
{
    public function blockedBy(User $viewer, User $other): bool
    {
        return DB::table('direct_message_blocks')
            ->where('blocker_user_id', $viewer->canonical()->id)
            ->where('blocked_user_id', $other->canonical()->id)->exists();
    }

    public function blockedBetween(User $first, User $second): bool
    {
        $one = $first->canonical()->id;
        $two = $second->canonical()->id;

        return DB::table('direct_message_blocks')->where(function ($q) use ($one, $two): void {
            $q->where(fn ($sub) => $sub->where('blocker_user_id', $one)->where('blocked_user_id', $two))
                ->orWhere(fn ($sub) => $sub->where('blocker_user_id', $two)->where('blocked_user_id', $one));
        })->exists();
    }

    /** Persist exactly one timeline event for each actual state transition. */
    public function update(User $viewer, User $other, bool $blocked): bool
    {
        $viewer = $viewer->canonical();
        $other = $other->canonical();
        abort_if($viewer->id === $other->id || $viewer->isBlocked() || $viewer->trashed(), 403);

        return DB::transaction(function () use ($viewer, $other, $blocked): bool {
            $ids = [(int) $viewer->id, (int) $other->id];
            sort($ids);
            // Same deterministic user-row locks as the message sender.
            User::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get(['id']);
            if ($blocked) {
                $changed = DB::table('direct_message_blocks')->insertOrIgnore([
                    'blocker_user_id' => $viewer->id, 'blocked_user_id' => $other->id,
                    'created_at' => now(), 'updated_at' => now(),
                ]) > 0;
            } else {
                $changed = DB::table('direct_message_blocks')
                    ->where('blocker_user_id', $viewer->id)
                    ->where('blocked_user_id', $other->id)->delete() > 0;
            }
            if (! $changed) {
                return false;
            }
            $conversation = DirectConversation::query()->where('user_low_id', $ids[0])
                ->where('user_high_id', $ids[1])->first();
            // Blocking before the first message must not create an empty conversation.
            if ($conversation !== null) {
                $conversation->messages()->create([
                    'sender_user_id' => $viewer->id,
                    'kind' => $blocked ? 'blocked' : 'unblocked',
                    'body' => $blocked ? 'Пользователь заблокирован' : 'Блокировка снята',
                ]);
                $conversation->touch();
            }

            return true;
        });
    }
}
