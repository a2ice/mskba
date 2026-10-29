<?php

namespace App\Modules\Notification\Application\Services;

use App\Modules\Identity\Application\Services\UserOperationalPermissionChecker;
use App\Modules\Identity\Domain\Enums\UserOperationalPermissionEnum;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\ProfileChangeRequest;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Notification\Domain\Models\RequestThread;
use App\Modules\Notification\Domain\Models\RequestThreadMembership;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/** Pilot adapter: the thread and membership engine remains request-type agnostic. */
final class ProfileRequestThreadAccess
{
    public function __construct(private readonly UserOperationalPermissionChecker $permissions) {}

    public function isReviewer(User $user): bool
    {
        return $this->permissions->allows($user, UserOperationalPermissionEnum::REVIEW_PROFILE_CHANGES);
    }

    public function thread(ProfileChangeRequest $request): RequestThread
    {
        return RequestThread::query()->firstOrCreate(
            ['type' => 'profile_change', 'subject_id' => $request->id],
        );
    }

    public function membership(User $user, RequestThread $thread): ?RequestThreadMembership
    {
        return $thread->relationLoaded('memberships')
            ? $thread->memberships->firstWhere('user_id', $user->canonical()->id)
            : $thread->memberships()->where('user_id', $user->canonical()->id)->first();
    }

    public function isApplicant(User $user, ProfileChangeRequest $request): bool
    {
        return (int) $user->canonical()->id === (int) $request->user_id;
    }

    public function canRead(User $user, ProfileChangeRequest $request, RequestThread $thread): bool
    {
        $user = $user->canonical();
        if ($user->isBlocked() || $user->trashed()) {
            return false;
        }
        if ($this->isApplicant($user, $request)) {
            return true;
        }
        $member = $this->membership($user, $thread);
        if ($member?->removed_at !== null || ! $this->isReviewer($user)) {
            return false;
        }
        if ($member !== null) {
            // An explicit invitation grants this one historical thread, not any other.
            return true;
        }
        $afterId = $this->eligibleAfterId($user);

        return $afterId !== null && $request->id > $afterId;
    }

    public function canWrite(User $user, ProfileChangeRequest $request, RequestThread $thread): bool
    {
        if ($request->status !== ProfileChangeRequest::PENDING || ! $this->canRead($user, $request, $thread)) {
            return false;
        }
        if ($this->isApplicant($user, $request)) {
            return true;
        }
        $member = $this->membership($user, $thread);

        return $member !== null && $member->removed_at === null;
    }

    /** Id cursor is monotonic and not affected by same-second timestamps. */
    public function eligibleAfterId(User $user): ?int
    {
        $user = $user->canonical();
        if (! $this->isReviewer($user)) {
            return null;
        }
        $permission = UserOperationalPermissionEnum::REVIEW_PROFILE_CHANGES;
        $cursor = DB::table('review_permission_cursors')
            ->where('user_id', $user->id)->where('permission', $permission->value)
            ->value('after_request_id');
        if ($cursor !== null) {
            return (int) $cursor;
        }

        // Without a cursor even a new superadmin must not see old requests.
        // The next submitted request initializes this cursor before insertion.
        return null;
    }

    public function initializeNewSuperadminCursors(): void
    {
        // Before inserting the next request, initialize cursors for new
        // confirmed admins and superadmins. Never expose pre-role history;
        // existing admins receive a migration backfill at the current max id.
        $permission = UserOperationalPermissionEnum::REVIEW_PROFILE_CHANGES->value;
        $baseline = (int) (ProfileChangeRequest::query()->max('id') ?? 0);
        $ids = User::query()
            ->whereIn('system_role', [
                UserSystemRoleEnum::SUPERADMIN->value, UserSystemRoleEnum::ADMIN->value,
            ])
            ->where('status', UserStatusEnum::CONFIRMED->value)
            ->whereDoesntHave('operationalPermissions', fn ($q) => $q
                ->where('permission', $permission)->where('is_allowed', false))
            ->whereNotExists(function ($q) use ($permission): void {
                $q->selectRaw('1')->from('review_permission_cursors')
                    ->whereColumn('review_permission_cursors.user_id', 'users.id')
                    ->where('permission', $permission);
            })->pluck('id');
        if ($ids->isEmpty()) {
            return;
        }
        $now = now();
        DB::table('review_permission_cursors')->insertOrIgnore(
            $ids->map(fn (int $id) => [
                'user_id' => $id, 'permission' => $permission,
                'after_request_id' => $baseline, 'created_at' => $now, 'updated_at' => $now,
            ])->all()
        );
    }

    /** No joins and no global history permission: EXISTS on the membership index. */
    public function visibleRequests(User $user): Builder
    {
        $user = $user->canonical();
        $cutoff = $this->eligibleAfterId($user);

        return ProfileChangeRequest::query()->where(function (Builder $query) use ($user, $cutoff): void {
            $query->where('user_id', $user->id)
                ->orWhereExists(function ($sub) use ($user): void {
                    $sub->selectRaw('1')->from('request_threads')
                        ->where('request_threads.type', 'profile_change')
                        ->whereColumn('request_threads.subject_id', 'profile_change_requests.id')
                        ->whereExists(function ($member) use ($user): void {
                            $member->selectRaw('1')->from('request_thread_memberships')
                                ->whereColumn('request_thread_memberships.request_thread_id', 'request_threads.id')
                                ->where('request_thread_memberships.user_id', $user->id);
                        });
                });
            if ($cutoff !== null) {
                $query->orWhere('id', '>', $cutoff);
            }
        });
    }
}
