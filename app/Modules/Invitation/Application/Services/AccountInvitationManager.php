<?php

namespace App\Modules\Invitation\Application\Services;

use App\Modules\Contact\Application\DTO\CreateContactDTO;
use App\Modules\Contact\Application\Services\ContactOwnership;
use App\Modules\Contact\Application\UseCases\CreateContactForUserHandler;
use App\Modules\Contact\Domain\Enums\ContactTypeEnum;
use App\Modules\Venue\Application\UseCases\AdminManageVenueContactsHandler;
use App\Modules\Contact\Domain\Enums\ContactVerificationStatusEnum;
use App\Modules\Contact\Domain\ValueObjects\ContactValue;
use App\Modules\Contract\Domain\Enums\VenueMembershipAccessLevelEnum as VenueRole;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Invitation\Domain\Models\AccountInvitation;
use App\Modules\Venue\Application\Services\VenueMembershipAccess;
use App\Modules\Venue\Application\Services\VenueCommercialAccess;
use App\Modules\Venue\Domain\Enums\VenuePermissionEnum;
use App\Modules\Venue\Application\UseCases\ManageVenueMembershipHandler;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\Venue\Domain\Models\VenueOwnership;
use App\Modules\Invitation\Presentation\Mail\AccountInvitationMail;
use App\Modules\Invitation\Presentation\Mail\AccountInvitationAcceptedMail;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class AccountInvitationManager
{
    private function authorizeActor(?User $actor): User
    {
        $user = $this->lockCanonicalUser($actor);
        abort_unless($user !== null && app(AccountInvitationAccess::class)->canViewAny($user), 403);

        return $user;
    }

    public function create(
        User $issuer, string $email, string $scopeType, int $scopeId,
        string $role, string $title, ?string $description, int $days, ?int $venueContactId = null
    ): array {
        abort_unless(app(AccountInvitationAccess::class)->canIssueAny($issuer), 403);
        if ($scopeType !== 'venue' || ! in_array($role, ['owner', 'staff', 'manager', 'booking_operator'], true)) {
            throw ValidationException::withMessages(['role' => 'Этот контекст или тип приглашения пока не поддерживается.']);
        }
        $email = (new ContactValue(ContactTypeEnum::EMAIL, $email))->value();
        if ($days < 1 || $days > 30) {
            throw ValidationException::withMessages(['days' => 'Срок ссылки должен быть от 1 до 30 дней.']);
        }
        $token = bin2hex(random_bytes(32));
        $invitation = DB::transaction(function () use ($issuer, $email, $scopeType, $scopeId, $role, $title, $description, $days, $token, $venueContactId) {
            $venue = Venue::query()->whereKey($scopeId)->lockForUpdate()->firstOrFail();
            $issuer = $this->authorizeActor($issuer);
            abort_unless(app(AccountInvitationAccess::class)->canIssue($issuer, $venue, $role), 403);
            if ($venueContactId !== null) {
                abort_unless(app(AdminManageVenueContactsHandler::class)->canManage($issuer, $venue), 403);
                $contact = $venue->contacts()->whereKey($venueContactId)->lockForUpdate()->firstOrFail();
                if ($contact->type !== ContactTypeEnum::EMAIL || ! $contact->hasBeenVerified() || $contact->value !== $email) {
                    throw ValidationException::withMessages(['email' => 'Контакт изменился или не подтверждён. Обновите редактор площадки.']);
                }
            }
            $this->assertTarget($scopeType, $scopeId, $role);
            $title = trim($title) ?: $this->defaultTitle($scopeId, $role);
            $this->assertNoActiveDuplicate($email, $scopeType, $scopeId, $role);
            $record = AccountInvitation::query()->create([
                'status' => 'pending',
                'progress' => 'created',
                'views_count' => 0,
                'public_id' => (string) Str::uuid(),
                'token_hash' => hash('sha256', $token),
                'contact_type' => ContactTypeEnum::EMAIL->value,
                'contact_value' => $email,
                'scope_type' => $scopeType,
                'scope_id' => $scopeId,
                'role' => $role,
                // Owner is evidence-backed and never granted by an email invitation.
                'proposed_permissions' => $role === 'owner' ? null : array_map(
                    static fn (VenuePermissionEnum $permission): string => $permission->value,
                    VenueRole::from($role)->defaultPermissions(),
                ),
                'title' => trim($title),
                'description' => filled($description) ? trim($description) : null,
                'expires_at' => now()->addDays($days),
                'created_by_user_id' => $issuer->id,
            ]);
            $this->event($record, 'created', [
                'issuer_id' => $issuer->id,
                'proposed_permissions' => $record->proposed_permissions,
                ...($venueContactId !== null ? ['venue_contact_id' => $venueContactId] : []),
            ]);
            return $record;
        }, 3);
        $this->send($invitation, $token);
        return [$invitation->refresh(), route('account-invitations.show', $token)];
    }

    public function renew(AccountInvitation $invitation, User $issuer, int $days = 7): string
    {
        if ($days < 1 || $days > 30 || ! $invitation->canManageLink()) {
            throw ValidationException::withMessages(['days' => 'Это приглашение нельзя продлить.']);
        }
        $token = bin2hex(random_bytes(32));
        DB::transaction(function () use ($invitation, $days, $token, $issuer): void {
            Venue::query()->whereKey($invitation->scope_id)->lockForUpdate()->firstOrFail();
            $record = AccountInvitation::query()->lockForUpdate()->findOrFail($invitation->id);
            $issuer = $this->authorizeActor($issuer);
            abort_unless(app(AccountInvitationAccess::class)->canManage($issuer, $record), 403);
            abort_unless($record->canManageLink(), 409);
            $this->assertTarget($record->scope_type, $record->scope_id, $record->role);
            $this->assertNoActiveDuplicate($record->contact_value, $record->scope_type, $record->scope_id, $record->role, $record->id);
            $previousExpiry = $record->expires_at;
            $record->forceFill([
                'token_hash' => hash('sha256', $token),
                'expires_at' => now()->addDays($days),
            ])->save();
            $this->event($record, 'link_renewed', [
                'issuer_id' => $issuer->id,
                'previous_expires_at' => $previousExpiry->toIso8601String(),
                'expires_at' => $record->expires_at->toIso8601String(),
            ]);
        }, 3);
        $this->send($invitation->refresh(), $token);
        return route('account-invitations.show', $token);
    }

    public function restore(AccountInvitation $invitation, User $issuer, int $days, string $reason): string
    {
        $reason = trim($reason);
        if ($days < 1 || $days > 30) {
            throw ValidationException::withMessages(['days' => 'Срок ссылки должен быть от 1 до 30 дней.']);
        }
        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 2000) {
            throw ValidationException::withMessages(['reason' => 'Укажите причину восстановления (от 5 до 2000 символов).']);
        }
        $token = bin2hex(random_bytes(32));
        DB::transaction(function () use ($invitation, $issuer, $days, $reason, $token): void {
            Venue::query()->whereKey($invitation->scope_id)->lockForUpdate()->firstOrFail();
            $record = AccountInvitation::query()->lockForUpdate()->findOrFail($invitation->id);
            $issuer = $this->authorizeActor($issuer);
            abort_unless(app(AccountInvitationAccess::class)->canManage($issuer, $record), 403);
            abort_unless($record->status === 'revoked' && $record->accepted_user_id === null, 409);
            $this->assertTarget($record->scope_type, $record->scope_id, $record->role);
            $this->assertNoActiveDuplicate($record->contact_value, $record->scope_type, $record->scope_id, $record->role, $record->id);
            $previousExpiry = $record->expires_at;
            $previousRevocation = $record->revoked_at;
            $record->forceFill([
                'status' => 'pending',
                'revoked_at' => null,
                'token_hash' => hash('sha256', $token),
                'expires_at' => now()->addDays($days),
            ])->save();
            $this->event($record, 'restored', [
                'issuer_id' => $issuer->id,
                'reason' => $reason,
                'previous_revoked_at' => $previousRevocation?->toIso8601String(),
                'previous_expires_at' => $previousExpiry->toIso8601String(),
                'expires_at' => $record->expires_at->toIso8601String(),
            ]);
        }, 3);
        $this->send($invitation->refresh(), $token);
        return route('account-invitations.show', $token);
    }

    private function assertNoActiveDuplicate(string $email, string $scopeType, int $scopeId, string $role, ?int $exceptId = null): void
    {
        if (AccountInvitation::query()->where('contact_value', $email)
            ->where('scope_type', $scopeType)->where('scope_id', $scopeId)
            ->where('role', $role)->where('status', 'pending')
            ->when($exceptId !== null, fn ($query) => $query->where('id', '!=', $exceptId))
            ->where(fn ($query) => $query->whereNotNull('accepted_user_id')
                ->orWhere('expires_at', '>', now()))->exists()) {
            throw ValidationException::withMessages(['email' => 'Для этого контакта и роли уже есть активное приглашение.']);
        }
    }

    public function revoke(AccountInvitation $invitation, User $issuer): void
    {
        DB::transaction(function () use ($invitation, $issuer): void {
            Venue::query()->whereKey($invitation->scope_id)->lockForUpdate()->firstOrFail();
            $record = AccountInvitation::query()->lockForUpdate()->findOrFail($invitation->id);
            $issuer = $this->authorizeActor($issuer);
            abort_unless(app(AccountInvitationAccess::class)->canManage($issuer, $record), 403);
            abort_unless($record->canManageLink(), 409);
            $record->forceFill(['status' => 'revoked', 'revoked_at' => now()])->save();
            $this->event($record, 'revoked', ['issuer_id' => $issuer->id]);
        });
    }

    public function findByToken(string $token): AccountInvitation
    {
        abort_unless((bool) preg_match('/^[a-f0-9]{64}$/', $token), 404);
        return AccountInvitation::query()->where('token_hash', hash('sha256', $token))->firstOrFail();
    }

    public function seen(AccountInvitation $invitation): void
    {
        if ($invitation->status !== 'pending' || $invitation->isExpired()) {
            return;
        }
        DB::transaction(function () use ($invitation): void {
            $record = AccountInvitation::query()->lockForUpdate()->findOrFail($invitation->id);
            if ($record->status !== 'pending' || $record->isExpired()
                || ! hash_equals($record->token_hash, $invitation->token_hash)) {
                return;
            }
            $record->increment('views_count');
            if ($record->viewed_at === null) {
                $fields = ['viewed_at' => now()];
                if (in_array($record->progress, ['created', 'sent'], true)) {
                    $fields['progress'] = 'viewed';
                }
                $record->forceFill($fields)->save();
                $this->event($record, 'viewed');
            }
        });
    }

    /**
     * Explicit interaction with a valid invitation, not identity verification
     * or acceptance. Repeated clicks preserve the first timestamp and event.
     */
    public function markInterested(AccountInvitation $invitation): bool
    {
        return DB::transaction(function () use ($invitation): bool {
            $record = AccountInvitation::query()->lockForUpdate()->findOrFail($invitation->id);
            if (! $record->canManageLink() || $record->isExpired()
                || ! hash_equals($record->token_hash, $invitation->token_hash)) {
                return false;
            }

            if ($record->interested_at === null) {
                $fields = ['interested_at' => now()];
                if (in_array($record->progress, ['created', 'sent', 'viewed'], true)) {
                    $fields['progress'] = 'interested';
                }
                $record->forceFill($fields)->save();
                $this->event($record, 'interested');
            }

            return true;
        }, 3);
    }

    public function accept(AccountInvitation $invitation, User $user, bool $registered = false): AccountInvitation
    {
        return DB::transaction(function () use ($invitation, $user, $registered): AccountInvitation {
            // Match the venue → invitation order used by renewal and ownership review.
            Venue::query()->whereKey($invitation->scope_id)->lockForUpdate()->firstOrFail();
            $record = AccountInvitation::query()->lockForUpdate()->findOrFail($invitation->id);
            abort_unless($record->status === 'pending'
                && hash_equals($record->token_hash, $invitation->token_hash)
                && (! $record->isExpired() || $record->accepted_user_id !== null), 410);
            $user = $user->canonical();
            abort_if($user->isBlocked() || $user->trashed(), 403);
            if ($record->accepted_user_id !== null) {
                abort_unless($user->isSameIdentity((int) $record->accepted_user_id), 403);

                // A previously consumed link is not new evidence of control
                // over an email removed from the accepting identity.
                return $this->advance($record, $user);
            }

            $ownership = app(ContactOwnership::class);
            $ownership->lock(ContactTypeEnum::EMAIL, $record->contact_value);
            $ownership->assertAvailable(ContactTypeEnum::EMAIL, $record->contact_value, $user->identityIds());
            User::query()->whereKey($user->identityIds())->orderBy('id')->lockForUpdate()->get();
            $contact = $user->identityContactsQuery()
                ->where('type', ContactTypeEnum::EMAIL->value)
                ->where('value', $record->contact_value)->first();
            if ($contact === null) {
                $contact = app(CreateContactForUserHandler::class)->handle(
                    $user, new CreateContactDTO(ContactTypeEnum::EMAIL, $record->contact_value)
                );
            }
            if ($contact->verified_at === null) {
                $ownership->claim($contact);
                $contact->verifications()->where('status', ContactVerificationStatusEnum::PENDING->value)
                    ->update(['status' => ContactVerificationStatusEnum::CANCELLED->value]);
            }

            if ($record->accepted_user_id === null) {
                $record->accepted_user_id = $user->id;
                if ($registered) {
                    $record->registered_at = now();
                    $this->event($record, 'registered', ['user_id' => $user->id]);
                }
                $record->contact_verified_at = now();
                $this->event($record, 'contact_verified', ['user_id' => $user->id]);
                $record->progress = 'contact_verified';
            }
            $record->save();

            $this->advance($record, $user);
            return $record->refresh();
        });
    }

    public function advance(AccountInvitation $invitation, User $user): AccountInvitation
    {
        if ($invitation->status !== 'pending' || $invitation->accepted_user_id === null) {
            return $invitation;
        }

        return DB::transaction(function () use ($invitation, $user): AccountInvitation {
            $venue = Venue::query()->whereKey($invitation->scope_id)->lockForUpdate()->firstOrFail();
            $record = AccountInvitation::query()->lockForUpdate()->findOrFail($invitation->id);
            $user = $this->lockCanonicalUser($user);

            if ($user === null || $user->trashed() || $user->isBlocked()
                || $record->status !== 'pending' || $record->accepted_user_id === null
                || ! $user->isSameIdentity((int) $record->accepted_user_id)) {
                return $record;
            }

            if ($record->role === 'owner') {
                $owner = VenueOwnership::query()->where('venue_id', $venue->id)
                    ->whereIn('owner_user_id', $user->identityIds())
                    ->where('status', 'active')->where('active_marker', true)->exists();
                if ($owner) {
                    return $this->complete($record);
                }
                $this->progress($record, 'awaiting_ownership');

                return $record->refresh();
            }

            // A recorded hold requires an explicit review, even if the
            // original issuer regains their privileges later.
            if ($record->progress === 'awaiting_authorization') {
                return $record;
            }

            if (! $this->recipientReady($record, $user)) {
                $this->progress($record, 'awaiting_account');

                return $record->refresh();
            }

            $issuer = $this->lockCanonicalUser(User::query()->find($record->created_by_user_id));
            $permissions = $this->proposedPermissions($record);
            $access = app(AccountInvitationAccess::class);
            if ($permissions === null || $issuer === null || $issuer->isSameIdentity($user)
                || ! $access->canIssueAny($issuer)
                || ! $access->canGrantPermissions($issuer, $venue, $record->role, $permissions)) {
                $this->progress($record, 'awaiting_authorization', [
                    'issuer_id' => $record->created_by_user_id,
                    'reason' => $permissions === null ? 'invalid_permission_snapshot' : 'issuer_no_longer_authorized',
                ]);

                return $record->refresh();
            }

            return $this->grantAndComplete($record, $venue, $user, $issuer, $permissions);
        }, 3);
    }

    public function approveCompletion(AccountInvitation $invitation, User $reviewer, string $reason): AccountInvitation
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 10 || mb_strlen($reason) > 500) {
            throw ValidationException::withMessages(['reason' => 'Укажите основание согласования (10–500 символов).']);
        }

        return DB::transaction(function () use ($invitation, $reviewer, $reason): AccountInvitation {
            $venue = Venue::query()->whereKey($invitation->scope_id)->lockForUpdate()->firstOrFail();
            $record = AccountInvitation::query()->lockForUpdate()->findOrFail($invitation->id);
            $reviewer = $this->lockCanonicalUser($reviewer);
            abort_unless($reviewer?->system_role === UserSystemRoleEnum::SUPERADMIN
                && $this->canAuthorizeMembership($reviewer, $venue), 403);
            abort_unless($record->status === 'pending' && $record->progress === 'awaiting_authorization'
                && $record->scope_type === 'venue' && in_array($record->role, ['staff', 'manager', 'booking_operator'], true), 409);

            $user = $this->lockCanonicalUser(User::query()->find($record->accepted_user_id));
            abort_unless($user !== null && ! $reviewer->isSameIdentity($user), 403);
            if (! $this->recipientReady($record, $user)) {
                throw ValidationException::withMessages(['reason' => 'Получатель должен выполнить условия подтверждения аккаунта и сохранить подтверждённый email приглашения.']);
            }

            $permissions = $this->proposedPermissions($record);
            if ($permissions === null || ! app(AccountInvitationAccess::class)->canGrantPermissions($reviewer, $venue, $record->role, $permissions)) {
                throw ValidationException::withMessages(['reason' => 'Предложенные полномочия больше недоступны. Требуется отдельное согласование нового договора.']);
            }

            $this->event($record, 'authorization_reviewed', [
                'issuer_id' => $record->created_by_user_id,
                'reviewer_id' => $reviewer->id,
                'reason' => $reason,
            ]);

            return $this->grantAndComplete($record, $venue, $user, $reviewer, $permissions);
        }, 3);
    }

    private function lockCanonicalUser(?User $user): ?User
    {
        if ($user === null) {
            return null;
        }

        $canonical = User::query()->whereKey($user->canonical()->id)->lockForUpdate()->first();

        return $canonical !== null && $canonical->canonical_user_id === null ? $canonical : null;
    }

    private function recipientReady(AccountInvitation $record, User $user): bool
    {
        return $user->isConfirmed() && ! $user->isBlocked() && ! $user->trashed()
            && $user->missingAccountConfirmationRequirements() === []
            && $record->contact_verified_at !== null
            && $user->identityContactsQuery()
                ->where('type', ContactTypeEnum::EMAIL->value)
                ->where('value', $record->contact_value)
                ->whereNotNull('verified_at')->exists();
    }

    private function canAuthorizeMembership(?User $actor, Venue $venue): bool
    {
        return app(AccountInvitationAccess::class)->canMutate($actor)
            && app(VenueCommercialAccess::class)->allows($actor, $venue, VenuePermissionEnum::MANAGE_MEMBERSHIPS);
    }

    /** @return list<VenuePermissionEnum>|null Null means an invalid snapshot. */
    private function proposedPermissions(AccountInvitation $record): ?array
    {
        $role = VenueRole::tryFrom($record->role);
        if ($role === null || ! in_array($role, [VenueRole::STAFF, VenueRole::MANAGER, VenueRole::BOOKING_OPERATOR], true)) {
            return null;
        }
        // Compatibility for invitations issued before snapshot support.
        // Do not backfill a fabricated historical proposal in the database.
        if ($record->proposed_permissions === null) {
            return $role->defaultPermissions();
        }
        if (! is_array($record->proposed_permissions) || ! array_is_list($record->proposed_permissions)) {
            return null;
        }
        $permissions = [];
        foreach ($record->proposed_permissions as $value) {
            $permission = is_string($value) ? VenuePermissionEnum::tryFrom($value) : null;
            if ($permission === null || ! in_array($permission, $role->allowedPermissions(), true)) {
                return null;
            }
            $permissions[$permission->value] = $permission;
        }

        return array_values($permissions);
    }

    /** @param list<VenuePermissionEnum> $permissions */
    private function grantAndComplete(AccountInvitation $record, Venue $venue, User $user, User $issuer, array $permissions): AccountInvitation
    {
        abort_unless($record->scope_type === 'venue'
            && in_array($record->role, ['staff', 'manager', 'booking_operator'], true), 409);
        $role = VenueRole::from($record->role);
        $active = app(VenueMembershipAccess::class)->activeVenueMembershipsFor($user)
            ->first(fn ($m): bool => $m->scope_type->value === 'venue'
                && (int) $m->scope_id === (int) $venue->id
                && $m->access_level === $role->value);
        $reused = $active !== null;
        if ($active === null) {
            $active = app(ManageVenueMembershipHandler::class)->grant($venue, $user, $role, $issuer, $permissions);
        } else {
            // Existing contracts are independent: never widen or replace one
            // merely because an invitation was accepted.
            $existing = $active->contract->permissions->pluck('permission')->all();
            $requested = array_map(static fn (VenuePermissionEnum $permission): string => $permission->value, $permissions);
            if (array_diff($requested, $existing) !== []) {
                $this->progress($record, 'awaiting_authorization', ['reason' => 'existing_contract_differs']);
                return $record->refresh();
            }
        }
        $record->forceFill(['granted_membership_id' => $active->id])->save();
        $this->event($record, 'contract_linked', [
            'membership_id' => $active->id,
            'contract_id' => $active->contract_id,
            'reused' => $reused,
            'legacy_proposal' => $record->proposed_permissions === null,
        ]);

        return $this->complete($record);
    }

    /**
     * Called from the existing evidence-backed ownership approval transaction.
     * Acceptance of an invitation alone never grants the owner contract.
     */
    public function finishOwnership(int $venueId, int $ownerUserId): void
    {
        $records = AccountInvitation::query()
            ->where('scope_type', 'venue')->where('scope_id', $venueId)
            ->where('role', 'owner')->where('accepted_user_id', $ownerUserId)
            ->whereNotNull('contact_verified_at')->where('status', 'pending')
            ->lockForUpdate()->get();

        foreach ($records as $record) {
            $this->complete($record);
        }
    }

    private function complete(AccountInvitation $invitation): AccountInvitation
    {
        if ($invitation->status !== 'completed') {
            $invitation->forceFill(['status' => 'completed', 'progress' => 'completed', 'completed_at' => now()])->save();
            $this->event($invitation, 'completed');
        }
        return $invitation->refresh();
    }

    private function progress(AccountInvitation $invitation, string $stage, array $details = []): void
    {
        if ($invitation->progress !== $stage) {
            $invitation->forceFill(['progress' => $stage])->save();
            $this->event($invitation, $stage, $details);
        }
    }

    private function defaultTitle(int $venueId, string $role): string
    {
        $prefix = match ($role) {
            'owner' => 'Регистрация владельца площадки',
            'manager' => 'Регистрация менеджера площадки',
            'booking_operator' => 'Регистрация оператора бронирований',
            default => 'Регистрация сотрудника площадки',
        };
        return mb_substr($prefix.' «'.Venue::query()->findOrFail($venueId)->name.'»', 0, 160);
    }

    private function assertTarget(string $scopeType, int $scopeId, string $role): void
    {
        if ($scopeType !== 'venue' || ! in_array($role, ['owner', 'staff', 'manager', 'booking_operator'], true)) {
            throw ValidationException::withMessages(['role' => 'Этот контекст или тип приглашения пока не поддерживается.']);
        }
        $venue = Venue::query()->findOrFail($scopeId);
        if ($venue->trashed()) {
            throw ValidationException::withMessages(['scope_id' => 'Площадка удалена.']);
        }
        if ($role === 'owner' && app(VenueMembershipAccess::class)->hasActiveOwner($venue)) {
            throw ValidationException::withMessages(['role' => 'У площадки уже есть подтверждённый владелец. Нужна отдельная передача владения.']);
        }
    }

    /** Public contacts only; no access to transport credentials or private venue contacts. */
    public function publicPortalContacts(): array
    {
        return app(\App\Modules\Portal\Application\Services\PortalContactSettings::class)->publicContacts();
    }

    /**
     * Called once by the acceptance HTTP flow, after the account transaction.
     * Never queue, serialize, persist or report the generated secret.
     * Delivery failure leaves the valid account intact and recovery available.
     */
    public function sendAcceptanceNotice(AccountInvitation $invitation, User $user, ?string $generatedPassword = null): bool
    {
        $invitation->refresh();
        $user = $user->canonical();
        if ($invitation->accepted_user_id === null || ! $user->isSameIdentity((int) $invitation->accepted_user_id)
            || $user->isBlocked() || $user->trashed()
            || ($generatedPassword !== null && ($invitation->registered_at === null || ! $user->is_temporary_password))
            || ! $user->identityContactsQuery()->where('type', ContactTypeEnum::EMAIL->value)
                ->where('value', $invitation->contact_value)->whereNotNull('verified_at')->exists()) {
            return false;
        }

        try {
            if ($this->containsNonDeliveringTransport((string) config('mail.default', 'log'))) {
                $this->event($invitation, 'acceptance_mail_failed');
                return false;
            }
            Mail::to($invitation->contact_value)->send(new AccountInvitationAcceptedMail(
                $invitation->targetName(),
                $invitation->role,
                $invitation->contact_value,
                $user->username,
                $generatedPassword,
                route('login'),
                $this->publicPortalContacts(),
            ));
        } catch (\Throwable) {
            // Mail exceptions may contain a rendered message. Do not report them.
            $this->event($invitation, 'acceptance_mail_failed');
            return false;
        }
        $this->event($invitation, 'acceptance_mail_sent');

        return true;
    }

    private function send(AccountInvitation $invitation, string $token): void
    {
        $mailer = (string) config('mail.default', 'log');
        if ($this->containsNonDeliveringTransport($mailer)) {
            throw ValidationException::withMessages(['email' => 'Почтовый транспорт не настроен для настоящей отправки. Приглашение сохранено; настройте SMTP и обновите ссылку.']);
        }
        Mail::to($invitation->contact_value)->send(new AccountInvitationMail(
            $invitation, route('account-invitations.show', $token)
        ));
        $invitation->forceFill(['sent_at' => now(), 'progress' => in_array($invitation->progress, [null, 'created'], true) ? 'sent' : $invitation->progress])->save();
        $this->event($invitation, 'sent');
    }

    /**
     * A failover to log/array/null isn't a real delivery. Reject the chain
     * instead of reporting "sent" when SMTP fails.
     */
    private function containsNonDeliveringTransport(string $mailer, array $seen = []): bool
    {
        if (in_array($mailer, $seen, true)) {
            return false;
        }
        $seen[] = $mailer;
        $transport = (string) config("mail.mailers.{$mailer}.transport", $mailer);
        if (in_array($transport, ['log', 'array', 'null'], true)) {
            return true;
        }
        if (in_array($transport, ['failover', 'roundrobin'], true)) {
            foreach ((array) config("mail.mailers.{$mailer}.mailers", []) as $fallback) {
                if ($this->containsNonDeliveringTransport((string) $fallback, $seen)) {
                    return true;
                }
            }
        }
        return false;
    }

    private function event(AccountInvitation $invitation, string $event, array $details = []): void
    {
        $invitation->events()->create(['event' => $event, 'details' => $details, 'created_at' => now()]);
    }
}
