<?php

namespace App\Modules\Event\Application\Services;

use App\Modules\Contract\Application\Services\EntityContractManager;
use App\Modules\Contract\Domain\Enums\ContractFamilyEnum;
use App\Modules\Contract\Domain\Enums\ContractMembershipScopeTypeEnum as Scope;
use App\Modules\Contract\Domain\Enums\ContractStatusEnum;
use App\Modules\Contract\Domain\Models\Contract;
use App\Modules\Contract\Domain\Models\ContractMembership;
use App\Modules\Event\Domain\Enums\EventResponsibilityStatusEnum as ResponsibilityStatus;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Event\Domain\Models\EventParticipant;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleAssignerEnum;
use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Team\Domain\Enums\TeamInvitationStatusEnum as InvitationStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class EventContractResponsibilityService
{
    public function __construct(private readonly EntityContractManager $contracts) {}

    /**
     * Called by the Event model's created hook, so every creation path
     * (public, backoffice, rental, section projection, factory) follows the
     * same rule. A system actor without a user remains service-managed.
     */
    public function ensureOwner(Event $event): ?ContractMembership
    {
        $user = $event->organizerActor()->with('user')->first()?->user?->canonical();
        if ($user === null || $user->trashed() || $user->isBlocked()) {
            return null;
        }

        return $this->contracts->grantOwner(
            Scope::EVENT, $event->id, $user, $user, 'Организатор: '.$event->title,
        );
    }

    /** The selected backoffice organizer must accept when not the actual issuer. */
    public function markBackofficeOwnerPending(Event $event, User $issuer): void
    {
        $membership = ContractMembership::query()
            ->where('scope_type', Scope::EVENT->value)
            ->where('scope_id', $event->id)
            ->where('access_level', 'owner')
            ->with('contract')->lockForUpdate()->first();

        if ($membership === null || $issuer->isSameIdentity($membership->user_id)) {
            return;
        }

        $membership->update(['invitation_status' => InvitationStatus::PENDING]);
        $membership->contract->update(['assigned_by' => $issuer->canonical()->id]);

        DB::afterCommit(static function () use ($membership): void {
            app(\App\Modules\Notification\Application\UseCases\CreateUserNotificationHandler::class)
                ->handle(new \App\Modules\Notification\Application\DTO\CreateUserNotificationDTO(
                    userId: $membership->user_id,
                    type: \App\Modules\Notification\Domain\Enums\UserNotificationTypeEnum::REMINDER,
                    title: 'Приглашение стать организатором',
                    body: 'Подтвердите управление мероприятием.',
                    actionUrl: route('account.contracts'),
                    actionText: 'Рассмотреть',
                ));
        });
    }

    /**
     * One-time historical backfill; it deliberately does not send invitations
     * or change the accepted/pending decisions already made by users.
     */
    public function backfillParticipant(EventParticipant $participant): ?ContractMembership
    {
        if ($participant->responsibility_contract_membership_id !== null
            || ! in_array($participant->responsibility_status, [ResponsibilityStatus::ACCEPTED, ResponsibilityStatus::PENDING], true)) {
            return $participant->responsibilityContractMembership;
        }

        $rights = $participant->responsibilityPermissions()->get()
            ->map(fn ($right): string => $right->permission->value)
            ->unique()->values()->all();
        $contract = Contract::query()->create([
            'family' => ContractFamilyEnum::MEMBERSHIP,
            'name' => 'Ответственный за мероприятие #'.$participant->event_id,
            'status' => ContractStatusEnum::ACTIVE,
            'starts_at' => $participant->responsibility_requested_at ?? $participant->created_at ?? now(),
            'assigned_by' => $participant->responsibility_requested_by_user_id,
            'assigned_at' => $participant->responsibility_requested_at ?? now(),
            'assigner' => UserParticipationRoleAssignerEnum::USER,
            'comment' => EntityContractManager::COMMENT_MARKER,
        ]);
        $membership = $contract->membership()->create([
            'scope_type' => Scope::EVENT,
            'scope_id' => $participant->event_id,
            'user_id' => $participant->user_id,
            'access_level' => 'responsible',
            'invitation_status' => $participant->responsibility_status === ResponsibilityStatus::ACCEPTED
                ? InvitationStatus::ACCEPTED : InvitationStatus::PENDING,
        ]);
        $contract->permissions()->createMany(array_map(
            static fn (string $right): array => ['permission' => $right], $rights,
        ));
        $participant->update(['responsibility_contract_membership_id' => $membership->id]);

        return $membership->load('contract.permissions');
    }

    /** @param list<string> $rights */
    public function assign(Event $event, EventParticipant $participant, Actor $issuer, array $rights): ContractMembership
    {
        $actorUser = $issuer->user?->canonical();
        $target = $participant->user?->canonical();
        abort_unless($actorUser !== null && $target !== null, 403);

        $existing = $participant->responsibilityContractMembership()->with('contract.permissions')->first();
        if ($existing !== null && $existing->contract?->status === ContractStatusEnum::ACTIVE) {
            $old = $existing->contract->permissions->pluck('permission')->all();
            if (array_diff($rights, $old) === []) {
                if (array_diff($old, $rights) !== []) {
                    $existing->contract->permissions()->delete();
                    $existing->contract->permissions()->createMany(array_map(
                        static fn (string $permission): array => ['permission' => $permission], $rights,
                    ));
                }
                $this->syncParticipant($existing->refresh());

                return $existing;
            }

            // Enlarging the scope starts a new, bounded invitation. The old
            // active grant is revoked atomically and cannot persist in parallel.
            $existing->contract->update(['status' => ContractStatusEnum::INACTIVE, 'expires_at' => now()]);
            $existing->update(['invitation_status' => InvitationStatus::REVOKED]);
        }

        $name = $existing?->contract?->name ?? 'Ответственный: '.$event->title;
        $membership = $this->contracts->invite(Scope::EVENT, $event->id,
            $actorUser, $target, $name, $rights);
        $this->linkParticipant($participant, $membership);

        return $membership;
    }

    public function linkParticipant(EventParticipant $participant, ContractMembership $membership): void
    {
        abort_unless($membership->scope_type === Scope::EVENT
            && (int) $membership->scope_id === (int) $participant->event_id
            && (int) $membership->user_id === (int) $participant->user_id, 403);

        $participant->update(['responsibility_contract_membership_id' => $membership->id]);
        $this->syncParticipant($membership);
    }

    public function syncParticipant(ContractMembership $membership): void
    {
        if ($membership->scope_type !== Scope::EVENT) {
            return;
        }

        $participant = EventParticipant::query()
            ->where('responsibility_contract_membership_id', $membership->id)
            ->first();
        if ($participant === null) {
            return;
        }
        $membership->loadMissing('contract.permissions');
        $status = match ($membership->invitation_status) {
            InvitationStatus::PENDING => ResponsibilityStatus::PENDING,
            InvitationStatus::ACCEPTED => ResponsibilityStatus::ACCEPTED,
            InvitationStatus::DECLINED => ResponsibilityStatus::DECLINED,
            default => null,
        };
        if ($membership->contract?->status !== ContractStatusEnum::ACTIVE) {
            $status = null;
        }

        $participant->forceFill([
            'responsibility_status' => $status,
            'responsibility_responded_at' => $status === ResponsibilityStatus::PENDING ? null : now(),
        ])->save();

        $participant->responsibilityPermissions()->delete();
        if (in_array($status, [ResponsibilityStatus::ACCEPTED, ResponsibilityStatus::PENDING], true)) {
            $participant->responsibilityPermissions()->createMany(
                $membership->contract->permissions->map(
                    static fn ($right): array => ['permission' => $right->permission],
                )->all(),
            );
        }
    }

    public function revoke(EventParticipant $participant, User $issuer): void
    {
        $membership = $participant->responsibilityContractMembership()->first();
        if ($membership === null) {
            throw ValidationException::withMessages(['contract' => 'Назначение ответственного не найдено.']);
        }
        $this->contracts->revoke($membership, $issuer);
        $this->syncParticipant($membership->refresh());
        $participant->update(['responsibility_contract_membership_id' => null]);
    }
}
