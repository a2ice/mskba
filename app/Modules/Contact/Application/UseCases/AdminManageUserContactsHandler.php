<?php

namespace App\Modules\Contact\Application\UseCases;

use App\Modules\Audit\Domain\Models\AuditLog;
use App\Modules\Contact\Application\DTO\ConfirmContactVerificationDTO;
use App\Modules\Contact\Application\DTO\CreateContactDTO;
use App\Modules\Contact\Domain\Enums\ContactTypeEnum;
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

    private function audit(User $actor, User $target, Contact $contact, string $event): void
    {
        AuditLog::query()->create([
            'actor_id' => app(CurrentActorResolver::class)->resolve($actor->canonical(), null)?->id,
            'auditable_type' => User::class,
            'auditable_id' => $target->id,
            'event' => $event,
            'metadata' => [
                'contact_id' => $contact->id,
                'contact_type' => $contact->type->value,
                'actor_user_id' => $actor->canonical()->id,
            ],
        ]);
    }
}
