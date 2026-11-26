<?php

namespace App\Modules\Venue\Application\UseCases;

use App\Modules\Contact\Application\DTO\ConfirmContactVerificationDTO;
use App\Modules\Contact\Application\Exceptions\ContactVerificationException;
use App\Modules\Contact\Application\Services\ContactOwnership;
use App\Modules\Contact\Application\UseCases\ConfirmContactVerificationHandler;
use App\Modules\Contact\Application\UseCases\StartContactVerificationHandler;
use App\Modules\Contact\Domain\Enums\ContactTypeEnum;
use App\Modules\Contact\Domain\Enums\ContactVerificationStatusEnum;
use App\Modules\Contact\Domain\Models\Contact;
use App\Modules\Contact\Domain\ValueObjects\ContactValue;
use App\Modules\Identity\Application\Services\UserOperationalPermissionChecker;
use App\Modules\Identity\Domain\Enums\UserOperationalPermissionEnum as Permission;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Domain\Models\Venue;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AdminManageVenueContactsHandler
{
    public function canManage(?User $actor, Venue $venue): bool
    {
        $actor = $actor?->canonical();
        $permissions = app(UserOperationalPermissionChecker::class);

        return $actor !== null && $actor->isConfirmed() && ! $actor->isBlocked()
            && ! $actor->trashed() && ! $venue->trashed()
            && $permissions->allows($actor, Permission::BACKOFFICE_ACCESS)
            && $permissions->allows($actor, Permission::BACKOFFICE_VENUE_MANAGE);
    }

    public function add(User $actor, Venue $venue, array $data): Contact
    {
        return DB::transaction(function () use ($actor, $venue, $data): Contact {
            $venue = $this->authorize($actor, $venue);
            $attributes = $this->attributes($data);
            app(ContactOwnership::class)->lock($attributes['type'], $attributes['value']);
            // Venue contacts are directory entries, not identity claims.
            $this->assertUnique($venue, $attributes);
            $primary = ! $venue->contacts()->where('is_primary', true)->exists();

            return $venue->contacts()->create([
                ...$attributes,
                'is_primary' => $primary,
                'is_public' => (bool) ($data['is_public'] ?? false),
            ]);
        }, 3);
    }

    public function edit(User $actor, Venue $venue, int $contactId, array $data): Contact
    {
        return DB::transaction(function () use ($actor, $venue, $contactId, $data): Contact {
            $venue = $this->authorize($actor, $venue);
            $contact = $venue->contacts()->whereKey($contactId)->lockForUpdate()->firstOrFail();
            $this->assertEditable($contact);
            $attributes = $this->attributes($data);
            $changed = $contact->type !== $attributes['type'] || $contact->value !== $attributes['value'];
            if ($changed) {
                app(ContactOwnership::class)->lockMany([
                    [$contact->type, $contact->value], [$attributes['type'], $attributes['value']],
                ]);
                $this->assertUnique($venue, $attributes, $contactId);
                $this->cancelCodes($contact);
                // Old evidence is retained in audit and verification history,
                // but cannot be carried to a replacement address.
                $attributes['verified_at'] = null;
                $attributes['is_public'] = false;
                $attributes['meta'] = null;
            } else {
                $attributes['is_public'] = (bool) ($data['is_public'] ?? false);
            }
            $contact->update($attributes);

            return $contact->refresh();
        }, 3);
    }

    public function setPrimary(User $actor, Venue $venue, int $contactId): void
    {
        DB::transaction(function () use ($actor, $venue, $contactId): void {
            $venue = $this->authorize($actor, $venue);
            $contact = $venue->contacts()->whereKey($contactId)->lockForUpdate()->firstOrFail();
            foreach ($venue->contacts()->where('is_primary', true)->whereKeyNot($contactId)->lockForUpdate()->get() as $old) {
                $old->update(['is_primary' => false]);
            }
            $contact->update(['is_primary' => true]);
        }, 3);
    }

    public function setRentalContact(User $actor, Venue $venue, int $contactId): void
    {
        DB::transaction(function () use ($actor, $venue, $contactId): void {
            $venue = $this->authorize($actor, $venue);
            $contact = $this->email($venue, $contactId);
            if (! $contact->hasBeenVerified()) {
                throw ValidationException::withMessages(['contact' => 'Для заявок на аренду нужен подтверждённый email.']);
            }
            foreach ($venue->contacts()->where('is_rental_contact', true)->whereKeyNot($contactId)->lockForUpdate()->get() as $old) {
                $old->forceFill(['is_rental_contact' => false])->save();
            }
            $contact->forceFill(['is_rental_contact' => true])->save();
        }, 3);
    }

    public function clearRentalContact(User $actor, Venue $venue, int $contactId): void
    {
        DB::transaction(function () use ($actor, $venue, $contactId): void {
            $venue = $this->authorize($actor, $venue);
            $contact = $venue->contacts()->whereKey($contactId)->lockForUpdate()->firstOrFail();
            $contact->forceFill(['is_rental_contact' => false])->save();
        }, 3);
    }

    public function delete(User $actor, Venue $venue, int $contactId): void
    {
        DB::transaction(function () use ($actor, $venue, $contactId): void {
            $venue = $this->authorize($actor, $venue);
            $contact = $venue->contacts()->whereKey($contactId)->lockForUpdate()->firstOrFail();
            $this->assertEditable($contact);
            $primary = $contact->is_primary;
            $this->cancelCodes($contact);
            $contact->forceFill(['is_primary' => false, 'is_public' => false, 'verified_at' => null, 'is_rental_contact' => false])->save();
            $contact->delete();
            if ($primary) {
                $next = $venue->contacts()->orderByRaw('CASE WHEN verified_at IS NULL THEN 1 ELSE 0 END')
                    ->orderBy('id')->lockForUpdate()->first();
                $next?->update(['is_primary' => true]);
            }
        }, 3);
    }

    public function sendCode(User $actor, Venue $venue, int $contactId): void
    {
        // Do not automatically retry a transaction that sends email.
        DB::transaction(function () use ($actor, $venue, $contactId): void {
            $venue = $this->authorize($actor, $venue);
            app(StartContactVerificationHandler::class)->handle($this->email($venue, $contactId));
        });
    }

    public function confirmCode(User $actor, Venue $venue, int $contactId, string $code): void
    {
        if (! preg_match('/^\d{6}$/D', $code)) {
            throw ValidationException::withMessages(['code' => 'Введите шестизначный код.']);
        }

        $error = DB::transaction(function () use ($actor, $venue, $contactId, $code): ?ContactVerificationException {
            $venue = $this->authorize($actor, $venue);
            $contact = $this->email($venue, $contactId);
            try {
                app(ConfirmContactVerificationHandler::class)->handle($contact, new ConfirmContactVerificationDTO($code));
            } catch (ContactVerificationException $exception) {
                // Commit failed attempts; never reset the shared OTP limit.
                return $exception;
            }

            return null;
        });
        if ($error !== null) {
            throw $error;
        }
    }

    private function authorize(User $actor, Venue $venue): Venue
    {
        $actor = $actor->canonical()->fresh();
        $venue = Venue::query()->whereKey($venue->id)->lockForUpdate()->firstOrFail();
        if (! $this->canManage($actor, $venue)) {
            throw new AuthorizationException('Нет разрешения на управление контактами площадки.');
        }

        return $venue;
    }

    private function email(Venue $venue, int $contactId): Contact
    {
        $contact = $venue->contacts()->whereKey($contactId)->lockForUpdate()->firstOrFail();
        if ($contact->type !== ContactTypeEnum::EMAIL) {
            throw ValidationException::withMessages(['contact' => 'Подтверждение поддерживается только для email.']);
        }

        return $contact;
    }

    private function assertEditable(Contact $contact): void
    {
        if (! in_array($contact->type, [ContactTypeEnum::EMAIL, ContactTypeEnum::PHONE], true)) {
            throw ValidationException::withMessages(['contact' => 'Изменение этого типа контакта здесь недоступно.']);
        }
    }

    private function attributes(array $data): array
    {
        $type = ContactTypeEnum::tryFrom((string) ($data['type'] ?? ''));
        if (! in_array($type, [ContactTypeEnum::EMAIL, ContactTypeEnum::PHONE], true)) {
            throw ValidationException::withMessages(['type' => 'Выберите email или телефон.']);
        }
        try {
            $value = (new ContactValue($type, (string) ($data['value'] ?? '')))->value();
        } catch (\InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['value' => $exception->getMessage()]);
        }
        $label = trim((string) ($data['label'] ?? ''));
        $comment = trim((string) ($data['comment'] ?? ''));
        if (mb_strlen($value) > 254 || mb_strlen($label) > 100 || mb_strlen($comment) > 500) {
            throw ValidationException::withMessages(['value' => 'Проверьте длину контакта, названия и комментария.']);
        }

        return ['type' => $type, 'value' => $value, 'label' => $label ?: null, 'comment' => $comment ?: null];
    }

    private function assertUnique(Venue $venue, array $attributes, ?int $except = null): void
    {
        $query = $venue->contacts()->withTrashed()->where('type', $attributes['type']->value)
            ->where('value', $attributes['value']);
        if ($except !== null) {
            $query->whereKeyNot($except);
        }
        if ($query->exists()) {
            throw ValidationException::withMessages(['value' => 'Этот контакт уже есть или ранее был удалён у этой площадки.']);
        }
    }

    private function cancelCodes(Contact $contact): void
    {
        foreach ($contact->verifications()->where('status', ContactVerificationStatusEnum::PENDING->value)->lockForUpdate()->get() as $code) {
            $code->update(['status' => ContactVerificationStatusEnum::CANCELLED]);
        }
    }
}
