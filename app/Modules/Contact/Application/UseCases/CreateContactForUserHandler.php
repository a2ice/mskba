<?php

namespace App\Modules\Contact\Application\UseCases;

use App\Modules\Contact\Application\DTO\CreateContactDTO;
use App\Modules\Contact\Application\Services\ContactOwnership;
use App\Modules\Contact\Domain\Enums\ContactTypeEnum;
use App\Modules\Contact\Domain\Models\Contact;
use App\Modules\Contact\Domain\ValueObjects\ContactValue;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class CreateContactForUserHandler
{
    public function replacePending(User $user, CreateContactDTO $data, int $contactId): Contact
    {
        return DB::transaction(function () use ($user, $data, $contactId): Contact {
            $canonical = $user->canonical();
            $previous = $canonical->identityContactsQuery()->whereKey($contactId)->firstOrFail();
            $ownership = app(ContactOwnership::class);
            $ownership->lockMany([
                [$previous->type, $previous->value],
                [$data->type, $data->value],
            ]);
            User::query()->whereKey($canonical->identityIds())->orderBy('id')->lockForUpdate()->get();
            $old = $canonical->identityContactsQuery()->whereKey($contactId)->lockForUpdate()->firstOrFail();
            if ($old->hasBeenVerified() || $old->type !== ContactTypeEnum::EMAIL || $data->type !== $old->type) {
                throw new InvalidArgumentException('Заменить можно только неподтверждённый email на другой email.');
            }

            // Keep the old contact and its code if validation or delivery fails.
            $new = $this->handle($canonical, $data);
            app(StartContactVerificationHandler::class)->handle($new);
            $wasPrimary = $old->is_primary;
            app(DeleteContactHandler::class)->handle($old);
            if ($wasPrimary && ! $canonical->identityContactsQuery()->where('is_primary', true)->exists()) {
                $new->update(['is_primary' => true]);
            }

            return $new->refresh();
        });
    }

    public function handle(User $user, CreateContactDTO $contactData): Contact
    {
        try {
            return DB::transaction(function () use ($user, $contactData): Contact {
                $ownership = app(ContactOwnership::class);
                $ownership->lock($contactData->type, $contactData->value);
                $canonicalUser = $user->canonical();
                $identityIds = $canonicalUser->identityIds();
                User::query()
                    ->whereKey($identityIds)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();
                $lockedUser = User::query()->findOrFail($canonicalUser->getKey());

                $value = new ContactValue($contactData->type, $contactData->value);

                $ownership->assertAvailable($contactData->type, $value->value(), $identityIds);
                $hasActiveContacts = $lockedUser->identityContactsQuery()->exists();
                $existingContact = $lockedUser->identityContactsQuery()
                    ->withTrashed()
                    ->where('type', $contactData->type)
                    ->when($contactData->type === ContactTypeEnum::PHONE,
                        fn ($query) => $query->where('user_phone_key', $value->value()),
                        fn ($query) => $query->where('value', $value->value()))
                    ->first();

                if ($existingContact?->trashed() === false) {
                    throw new InvalidArgumentException("Контакт {$value->value()} уже добавлен.");
                }

                if ($existingContact?->trashed()) {
                    $existingContact->restore();
                    $existingContact->update([
                        'label' => $contactData->label,
                        'is_primary' => ! $hasActiveContacts,
                        'is_public' => false,
                    ]);

                    return $existingContact->refresh();
                }

                // if the user has no contacts yet, set the new contact as primary
                return $lockedUser->contacts()->create([
                    'type' => $contactData->type,
                    'value' => $value->value(),
                    'label' => $contactData->label,
                    'is_primary' => ! $hasActiveContacts,
                ]);
            });
        } catch (QueryException $exception) {
            // Translate the database's final protection after a concurrent claim.
            if (in_array($contactData->type, [ContactTypeEnum::EMAIL, ContactTypeEnum::PHONE], true)) {
                app(ContactOwnership::class)->assertAvailable(
                    $contactData->type, $contactData->value, $user->canonical()->identityIds(),
                );
            }

            throw $exception;
        }
    }
}
