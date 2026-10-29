<?php

namespace App\Modules\Identity\Application\Services;

use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Models\Profile;
use App\Modules\Identity\Domain\Models\ProfileChangeRequest;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Notification\Application\Services\ProfileRequestThreadAccess;
use App\Modules\Notification\Application\Services\RequestThreadMembershipManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ProfileChangeRequestService
{
    public function __construct(
        private readonly ProfileRequestThreadAccess $threadAccess,
        private readonly RequestThreadMembershipManager $members,
    ) {}

    private const FIELDS = ['first_name', 'last_name', 'birth_date', 'gender'];

    /** @return array<string, string|null> */
    public function snapshot(?Profile $profile): array
    {
        return [
            'first_name' => $profile?->first_name,
            'last_name' => $profile?->last_name,
            'birth_date' => $profile?->birth_date?->toDateString(),
            'gender' => $profile?->gender?->value,
        ];
    }

    /** @param array<string, string> $proposed */
    public function submit(User $actor, array $proposed, string $reason): ProfileChangeRequest
    {
        $canonicalId = $actor->canonical()->id;

        return DB::transaction(function () use ($canonicalId, $proposed, $reason): ProfileChangeRequest {
            $user = User::query()->whereKey($canonicalId)->lockForUpdate()->firstOrFail();
            abort_unless($user->status === UserStatusEnum::CONFIRMED && ! $user->trashed(), 403);

            // The scope is canonical user + confirmed_profile_change.
            $existing = ProfileChangeRequest::query()->where('user_id', $user->id)
                ->whereIn('status', [ProfileChangeRequest::PENDING, ProfileChangeRequest::BLOCKED])->exists();
            if ($existing) {
                throw ValidationException::withMessages([
                    'reason' => 'У тебя уже есть рассматриваемая либо заблокированная заявка.',
                ]);
            }

            $original = $this->snapshot($user->profile()->lockForUpdate()->first());
            if ($original === $proposed) {
                throw ValidationException::withMessages(['reason' => 'Укажи хотя бы одно изменение в данных профиля.']);
            }

            $this->threadAccess->initializeNewReviewerCursors();
            $request = ProfileChangeRequest::query()->create([
                'user_id' => $user->id,
                'status' => ProfileChangeRequest::PENDING,
                'original_data' => $original,
                'proposed_data' => $proposed,
                'reason' => $reason,
            ]);
            $this->record($request, $user, 'submit', $reason);
            $this->threadAccess->thread($request);

            return $request;
        });
    }

    public function cancel(User $actor, ProfileChangeRequest $request): void
    {
        $canonicalId = $actor->canonical()->id;
        DB::transaction(function () use ($canonicalId, $request): void {
            $user = User::query()->whereKey($canonicalId)->lockForUpdate()->firstOrFail();
            abort_if($user->isBlocked() || (int) $request->user_id !== (int) $user->id, 403);
            $locked = ProfileChangeRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->status === ProfileChangeRequest::PENDING, 422, 'Отменить можно только рассматриваемую заявку.');
            $locked->forceFill(['status' => ProfileChangeRequest::CANCELLED, 'cancelled_at' => now()])->save();
            $this->record($locked, $user, 'cancel');
        });
    }

    public function review(User $reviewer, ProfileChangeRequest $request, string $action, ?string $reason): void
    {
        abort_unless(in_array($action, ['approve', 'reject', 'block'], true), 422);
        $this->assertReviewer($reviewer);
        $reviewer = $reviewer->canonical();
        $thread = $this->threadAccess->thread($request);
        abort_unless($this->threadAccess->canRead($reviewer, $request, $thread), 403);

        DB::transaction(function () use ($reviewer, $request, $action, $reason): void {
            $user = User::query()->whereKey($request->user_id)->lockForUpdate()->firstOrFail();
            abort_if($reviewer->id === $user->id, 403, 'Нельзя рассматривать собственную заявку.');
            $reviewerLocked = User::query()->whereKey($reviewer->id)->lockForUpdate()->firstOrFail();
            $locked = ProfileChangeRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
            abort_unless($this->threadAccess->canRead($reviewerLocked, $locked, $this->threadAccess->thread($locked)), 403);
            abort_unless($locked->status === ProfileChangeRequest::PENDING, 422, 'Заявка уже рассмотрена.');

            if (in_array($action, ['reject', 'block'], true) && blank(trim((string) $reason))) {
                throw ValidationException::withMessages(['review_reason' => 'Укажи причину решения.']);
            }

            if ($action === 'approve') {
                abort_unless($user->status === UserStatusEnum::CONFIRMED, 422);
                $profile = $user->profile()->lockForUpdate()->firstOrFail();
                if ($this->snapshot($profile) !== $locked->original_data) {
                    throw ValidationException::withMessages(['review_reason' => 'Профиль изменился после подачи заявки. Нельзя применить устаревшие значения.']);
                }
            }

            // Claim and decision belong to the same outer transaction: a failed
            // validation or decision must not appoint a lead or create an audit event.
            $this->members->claim($reviewerLocked, $locked);
            if ($action === 'approve') {
                $profile->fill($locked->proposed_data)->save();
            }

            $locked->forceFill([
                'status' => match ($action) {
                    'approve' => ProfileChangeRequest::APPROVED,
                    'reject' => ProfileChangeRequest::REJECTED,
                    'block' => ProfileChangeRequest::BLOCKED,
                },
                'review_reason' => $reason,
                'reviewed_by_user_id' => $reviewer->id,
                'reviewed_at' => now(),
                'blocked_at' => $action === 'block' ? now() : null,
            ])->save();
            $this->record($locked, $reviewer, $action, $reason);
        });
    }

    public function unblock(User $reviewer, ProfileChangeRequest $request): void
    {
        $this->assertReviewer($reviewer);
        $reviewer = $reviewer->canonical();

        DB::transaction(function () use ($reviewer, $request): void {
            $user = User::query()->whereKey($request->user_id)->lockForUpdate()->firstOrFail();
            abort_if($reviewer->id === $user->id, 403);
            $reviewerLocked = User::query()->whereKey($reviewer->id)->lockForUpdate()->firstOrFail();
            $locked = ProfileChangeRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
            abort_unless($this->threadAccess->canRead($reviewerLocked, $locked, $this->threadAccess->thread($locked)), 403);
            abort_unless($locked->status === ProfileChangeRequest::BLOCKED, 422, 'Заявка не заблокирована.');
            $locked->forceFill([
                'status' => ProfileChangeRequest::REJECTED,
                'unblocked_by_user_id' => $reviewer->id,
                'unblocked_at' => now(),
            ])->save();
            $this->record($locked, $reviewer, 'unblock');
        });
    }

    private function assertReviewer(User $reviewer): void
    {
        $canonical = $reviewer->canonical();
        abort_unless($canonical->isConfirmed() && $this->threadAccess->isReviewer($canonical), 403);
    }

    private function record(ProfileChangeRequest $request, User $actor, string $action, ?string $reason = null): void
    {
        $request->events()->create([
            'actor_user_id' => $actor->id,
            'action' => $action,
            'reason' => $reason,
        ]);
    }
}
