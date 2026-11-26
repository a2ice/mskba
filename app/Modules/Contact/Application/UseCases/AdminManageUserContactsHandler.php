<?php

namespace App\Modules\Contact\Application\UseCases;

use App\Modules\Audit\Domain\Models\AuditLog;
use App\Modules\Contact\Application\DTO\ConfirmContactVerificationDTO;
use App\Modules\Contact\Application\DTO\CreateContactDTO;
use App\Modules\Contact\Application\Services\ContactOwnership;
use App\Modules\Contact\Domain\Enums\ContactTypeEnum;
use App\Modules\Contact\Domain\ValueObjects\ContactValue;
use App\Modules\Contact\Domain\Models\Contact;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Identity\Application\Services\GlobalOperationalPermissionManager;
use App\Modules\Identity\Application\Services\UserOperationalPermissionChecker;
use App\Modules\Identity\Domain\Enums\UserOperationalPermissionEnum as Permission;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum as Role;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AdminManageUserContactsHandler
{
    public function canManage(?User $actor, User $target): bool
    {
        $actor = $actor?->canonical();
        $target = $target->canonical();

        return $actor !== null
            && $actor->system_role === Role::SUPERADMIN
            && $target->system_role === Role::USER
            && ! $target->isBlocked()
            && ! $target->trashed()
            && app(GlobalOperationalPermissionManager::class)->mayManage($actor, $target)
            && app(UserOperationalPermissionChecker::class)->allows($actor, Permission::BACKOFFICE_ACCESS)
            && app(UserOperationalPermissionChecker::class)->allows($actor, Permission::EDIT_USER_PERSONAL_DATA);
    }

    public function add(User $actor, User $target, ContactTypeEnum $type, string $value, ?string $label): Contact
    {
        $target = $this->authorize($actor, $target);

        if (! in_array($type, [ContactTypeEnum::EMAIL, ContactTypeEnum::PHONE], true)) {
            throw ValidationException::withMessages(['type' => 'Этот канал добавляется только через официальную авторизацию.']);
        }

        $label = $label === null ? null : trim($label);
        if (mb_strlen($value) > 254 || mb_strlen((string) $label) > 100) {
            throw ValidationException::withMessages(['value' => 'Проверьте длину контакта и его названия.']);
        }

        return DB::transaction(function () use ($actor, $target, $type, $value, $label): Contact {
            $contact = app(CreateContactForUserHandler::class)->handle(
                $target, new CreateContactDTO($type, $value, $label ?: null),
            );
            $this->audit($actor, $target, $contact, 'admin_user_contact_added');

            return $contact;
        }, 3);
    }

    public function sendCode(User $actor, User $target, int $contactId): void
    {
        $target = $this->authorize($actor, $target);
        $contact = $this->email($target, $contactId);

        app(StartContactVerificationHandler::class)->handle($contact);
        $this->audit($actor, $target, $contact, 'admin_user_contact_code_sent');
    }

    public function confirmCode(User $actor, User $target, int $contactId, string $code): void
    {
        $target = $this->authorize($actor, $target);
        $contact = $this->email($target, $contactId);

        if (! preg_match('/^\d{6}$/D', $code)) {
            throw ValidationException::withMessages(['code' => 'Введите шестизначный код.']);
        }

        // Do not wrap a failing verification in an outer transaction: the
        // existing handler must retain the failed attempt and expiry state.
        app(ConfirmContactVerificationHandler::class)->handle(
            $contact, new ConfirmContactVerificationDTO($code),
        );
        $this->audit($actor, $target, $contact, 'admin_user_contact_verified');
    }

    public function setPrimary(User $actor, User $target, int $contactId): void
    {
        $target = $this->authorize($actor, $target);
        $contact = $target->identityContactsQuery()->findOrFail($contactId);

        DB::transaction(function () use ($actor, $target, $contact): void {
            app(SetPrimaryContactForUserHandler::class)->handle($target, $contact);
            $this->audit($actor, $target, $contact, 'admin_user_contact_primary_changed');
        }, 3);
    }

    public function updateLabel(User $actor, User $target, int $contactId, ?string $label, string $reason): void
    {
        $target = $this->authorize($actor, $target);
        $reason = $this->revisionReason($reason);
        $label = trim((string) $label);
        if (mb_strlen($label) > 100) {
            throw ValidationException::withMessages(['label' => 'Название не должно превышать 100 символов.']);
        }

        DB::transaction(function () use ($actor, $target, $contactId, $label, $reason): void {
            $contact = $target->identityContactsQuery()->whereKey($contactId)->lockForUpdate()->firstOrFail();
            $this->assertEditableType($contact);
            $before = $contact->label;
            $contact->update(['label' => $label === '' ? null : $label]);
            $this->audit($actor, $target, $contact, 'admin_user_contact_label_changed', [
                'reason' => $reason,
                'old_label' => $before,
                'new_label' => $contact->label,
            ]);
        }, 3);
    }

    public function replacePending(User $actor, User $target, int $contactId, string $value, string $reason): Contact
    {
        $target = $this->authorize($actor, $target);
        $reason = $this->revisionReason($reason);
        $previous = $target->identityContactsQuery()->findOrFail($contactId);
        $this->assertEditableType($previous);
        if ($previous->hasBeenVerified()) {
            throw ValidationException::withMessages(['value' => 'Подтверждённый контакт нельзя заменить. Добавьте и подтвердите новый контакт отдельно.']);
        }
        $value = (new ContactValue($previous->type, $value))->value();

        return DB::transaction(function () use ($actor, $target, $previous, $value, $reason): Contact {
            app(ContactOwnership::class)->lockMany([
                [$previous->type, $previous->value], [$previous->type, $value],
            ]);
            User::query()->whereKey($target->identityIds())->orderBy('id')->lockForUpdate()->get();
            $target = $this->authorize($actor, $target);
            $old = $target->identityContactsQuery()->whereKey($previous->id)->lockForUpdate()->firstOrFail();
            if ($old->hasBeenVerified() || $old->type !== $previous->type || $old->value !== $previous->value) {
                throw ValidationException::withMessages(['value' => 'Контакт изменился. Обновите редактор.']);
            }
            if ($old->value === $value) {
                throw ValidationException::withMessages(['value' => 'Введите другое значение контакта.']);
            }

            // A separate unverified record preserves the old verification history.
            // No email is sent until the administrator explicitly requests a code.
            $new = app(CreateContactForUserHandler::class)->handle(
                $target, new CreateContactDTO($old->type, $value, $old->label),
            );
            $wasPrimary = $old->is_primary;
            app(DeleteContactHandler::class)->handle($old);
            if ($wasPrimary && ! $target->identityContactsQuery()->where('is_primary', true)->exists()) {
                $new->update(['is_primary' => true]);
            }

            $this->audit($actor, $target, $new, 'admin_user_contact_replaced', [
                'reason' => $reason,
                'previous_contact_id' => $old->id,
            ]);

            return $new->refresh();
        }, 3);
    }

    public function delete(User $actor, User $target, int $contactId, string $reason): void
    {
        $target = $this->authorize($actor, $target);
        $reason = $this->revisionReason($reason);
        $contact = $target->identityContactsQuery()->findOrFail($contactId);
        $this->assertEditableType($contact);

        DB::transaction(function () use ($actor, $target, $contact, $reason): void {
            app(ContactOwnership::class)->lock($contact->type, $contact->value);
            User::query()->whereKey($target->identityIds())->orderBy('id')->lockForUpdate()->get();
            $target = $this->authorize($actor, $target);
            $locked = $target->identityContactsQuery()->whereKey($contact->id)->lockForUpdate()->firstOrFail();
            $this->assertEditableType($locked);
            app(DeleteContactHandler::class)->handle($locked);
            $this->audit($actor, $target, $locked, 'admin_user_contact_deleted', ['reason' => $reason]);
        }, 3);
    }

    private function assertEditableType(Contact $contact): void
    {
        if (! in_array($contact->type, [ContactTypeEnum::EMAIL, ContactTypeEnum::PHONE], true)) {
            throw ValidationException::withMessages(['contact' => 'Изменение привязок внешних провайдеров здесь недоступно.']);
        }
    }

    private function revisionReason(string $reason): string
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 10 || mb_strlen($reason) > 500) {
            throw ValidationException::withMessages(['reason' => 'Укажите основание (от 10 до 500 символов).']);
        }

        return $reason;
    }

    private function authorize(User $actor, User $target): User
    {
        $actor = $actor->canonical()->fresh();
        $target = $target->canonical()->fresh();

        if ($actor === null || $target === null || ! $this->canManage($actor, $target)) {
            throw new AuthorizationException('Нет разрешения на управление контактами пользователя.');
        }

        return $target;
    }

    private function email(User $target, int $contactId): Contact
    {
        $contact = $target->identityContactsQuery()->findOrFail($contactId);
        if ($contact->type !== ContactTypeEnum::EMAIL) {
            throw ValidationException::withMessages(['contact' => 'Отправка кода поддерживается только для email.']);
        }

        return $contact;
    }

    private function audit(User $actor, User $target, Contact $contact, string $event, array $metadata = []): void
    {
        AuditLog::query()->create([
            'actor_id' => app(CurrentActorResolver::class)->resolve($actor->canonical(), null)?->id,
            'auditable_type' => User::class,
            'auditable_id' => $target->id,
            'event' => $event,
            'metadata' => [
                ...$metadata,
                'contact_id' => $contact->id,
                'contact_type' => $contact->type->value,
                'actor_user_id' => $actor->canonical()->id,
            ],
        ]);
    }
}
