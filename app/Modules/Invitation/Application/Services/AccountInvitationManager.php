<?php

namespace App\Modules\Invitation\Application\Services;

use App\Modules\Contact\Application\DTO\CreateContactDTO;
use App\Modules\Contact\Application\Services\ContactOwnership;
use App\Modules\Contact\Application\UseCases\CreateContactForUserHandler;
use App\Modules\Contact\Domain\Enums\ContactTypeEnum;
use App\Modules\Contact\Domain\Enums\ContactVerificationStatusEnum;
use App\Modules\Contact\Domain\ValueObjects\ContactValue;
use App\Modules\Contract\Domain\Enums\VenueMembershipAccessLevelEnum as VenueRole;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Invitation\Domain\Models\AccountInvitation;
use App\Modules\Venue\Application\Services\VenueMembershipAccess;
use App\Modules\Venue\Application\UseCases\ManageVenueMembershipHandler;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\Venue\Domain\Models\VenueOwnership;
use App\Modules\Invitation\Presentation\Mail\AccountInvitationMail;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class AccountInvitationManager
{
    public function authorizeIssuer(?User $issuer): User
    {
        $user = $issuer?->canonical();
        abort_unless($user !== null && $user->isConfirmed()
            && $user->system_role === UserSystemRoleEnum::SUPERADMIN, 403);

        return $user;
    }

    public function create(
        User $issuer, string $email, string $scopeType, int $scopeId,
        string $role, string $title, ?string $description, int $days
    ): array {
        $issuer = $this->authorizeIssuer($issuer);
        $email = (new ContactValue(ContactTypeEnum::EMAIL, $email))->value();
        $this->assertTarget($scopeType, $scopeId, $role);
        $title = trim($title) ?: $this->defaultTitle($scopeId, $role);
        if ($days < 1 || $days > 30) {
            throw ValidationException::withMessages(['days' => 'Срок ссылки должен быть от 1 до 30 дней.']);
        }
        $token = bin2hex(random_bytes(32));
        $invitation = DB::transaction(function () use ($issuer, $email, $scopeType, $scopeId, $role, $title, $description, $days, $token) {
            Venue::query()->whereKey($scopeId)->lockForUpdate()->firstOrFail();
            $this->assertTarget($scopeType, $scopeId, $role);
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
                'title' => trim($title),
                'description' => filled($description) ? trim($description) : null,
                'expires_at' => now()->addDays($days),
                'created_by_user_id' => $issuer->id,
            ]);
            $this->event($record, 'created', ['issuer_id' => $issuer->id]);
            return $record;
        }, 3);
        $this->send($invitation, $token);
        return [$invitation->refresh(), route('account-invitations.show', $token)];
    }

    public function renew(AccountInvitation $invitation, User $issuer, int $days = 7): string
    {
        $issuer = $this->authorizeIssuer($issuer);
        if ($days < 1 || $days > 30 || ! $invitation->canManageLink()) {
            throw ValidationException::withMessages(['days' => 'Это приглашение нельзя продлить.']);
        }
        $token = bin2hex(random_bytes(32));
        DB::transaction(function () use ($invitation, $days, $token, $issuer): void {
            Venue::query()->whereKey($invitation->scope_id)->lockForUpdate()->firstOrFail();
            $record = AccountInvitation::query()->lockForUpdate()->findOrFail($invitation->id);
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
        $issuer = $this->authorizeIssuer($issuer);
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
        $issuer = $this->authorizeIssuer($issuer);
        DB::transaction(function () use ($invitation, $issuer): void {
            $record = AccountInvitation::query()->lockForUpdate()->findOrFail($invitation->id);
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

    public function accept(AccountInvitation $invitation, User $user, bool $registered = false): AccountInvitation
    {
        return DB::transaction(function () use ($invitation, $user, $registered): AccountInvitation {
            $record = AccountInvitation::query()->lockForUpdate()->findOrFail($invitation->id);
            abort_unless($record->status === 'pending'
                && hash_equals($record->token_hash, $invitation->token_hash)
                && (! $record->isExpired() || $record->accepted_user_id !== null), 410);
            $user = $user->canonical();
            abort_if($user->isBlocked() || $user->trashed(), 403);
            if ($record->accepted_user_id !== null && ! $user->isSameIdentity((int) $record->accepted_user_id)) {
                abort(403);
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
            }
            $record->progress = 'contact_verified';
            $record->save();

            $this->advance($record, $user);
            return $record->refresh();
        });
    }

    public function advance(AccountInvitation $invitation, User $user): AccountInvitation
    {
        $user = $user->canonical();
        if ($invitation->status !== 'pending' || ! $user->isSameIdentity((int) $invitation->accepted_user_id)) {
            return $invitation;
        }
        $venue = Venue::query()->findOrFail($invitation->scope_id);
        if ($invitation->role === 'owner') {
            $owner = VenueOwnership::query()->where('venue_id', $venue->id)
                ->whereIn('owner_user_id', $user->identityIds())
                ->where('status', 'active')->where('active_marker', true)->exists();
            if ($owner) {
                return $this->complete($invitation);
            }
            $this->progress($invitation, 'awaiting_ownership');
            return $invitation->refresh();
        }
        if (! $user->isConfirmed()) {
            $this->progress($invitation, 'awaiting_account');
            return $invitation->refresh();
        }
        // A commercial membership can only be issued by the existing domain
        // handler. The invitee explicitly accepted the invitation first.
        $role = VenueRole::from($invitation->role);
        $active = app(VenueMembershipAccess::class)->activeVenueMembershipsFor($user)
            ->first(fn ($m): bool => $m->scope_type->value === 'venue'
                && (int) $m->scope_id === (int) $venue->id
                && $m->access_level === $role->value);
        if ($active === null) {
            app(ManageVenueMembershipHandler::class)->grant(
                $venue, $user, $role, $invitation->issuer
            );
        }
        return $this->complete($invitation);
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

    private function progress(AccountInvitation $invitation, string $stage): void
    {
        if ($invitation->progress !== $stage) {
            $invitation->forceFill(['progress' => $stage])->save();
            $this->event($invitation, $stage);
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
