<?php

namespace App\Modules\Notification\Application\Services;

use App\Modules\Identity\Domain\Models\User;
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

    public function update(User $viewer, User $other, bool $blocked): void
    {
        $viewer = $viewer->canonical();
        $other = $other->canonical();
        abort_if($viewer->id === $other->id || $viewer->isBlocked() || $viewer->trashed(), 403);
        DB::transaction(function () use ($viewer, $other, $blocked): void {
            User::query()->whereIn('id', [$viewer->id, $other->id])->orderBy('id')
                ->lockForUpdate()->get(['id']);
            if ($blocked) {
                DB::table('direct_message_blocks')->insertOrIgnore([
                    'blocker_user_id' => $viewer->id, 'blocked_user_id' => $other->id,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            } else {
                DB::table('direct_message_blocks')
                    ->where('blocker_user_id', $viewer->id)
                    ->where('blocked_user_id', $other->id)->delete();
            }
        });
    }
}
