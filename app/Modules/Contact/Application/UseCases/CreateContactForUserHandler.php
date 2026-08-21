<?php

namespace App\Modules\Contact\Application\UseCases;

use App\Modules\Contact\Application\DTO\CreateContactDTO;
use App\Modules\Contact\Domain\Enums\ContactTypeEnum;
use App\Modules\Contact\Domain\Models\Contact;
use App\Modules\Contact\Domain\ValueObjects\ContactValue;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class CreateContactForUserHandler
{
    public function handle(User $user, CreateContactDTO $contactData): Contact
    {
        try {
            return DB::transaction(function () use ($user, $contactData): Contact {
                $canonicalUser = $user->canonical();
                $identityIds = $canonicalUser->identityIds();
                User::query()
                    ->whereKey($identityIds)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();
                $lockedUser = User::query()->findOrFail($canonicalUser->getKey());

                $value = new ContactValue($contactData->type, $contactData->value);

                if ($contactData->type === ContactTypeEnum::EMAIL) {
                    $emailAlreadyOwned = Contact::query()
                        ->withTrashed()
                        ->where('user_email_key', $value->value())
                        ->whereNot(function ($query) use ($identityIds): void {
                            $query->where('contactable_type', 'user')
                                ->whereIn('contactable_id', $identityIds);
                        })
                        ->exists();

                    if ($emailAlreadyOwned) {
                        throw new InvalidArgumentException('Этот email уже используется другим пользователем.');
                    }
                }
                $hasActiveContacts = $lockedUser->identityContactsQuery()->exists();
                $existingContact = $lockedUser->identityContactsQuery()
                    ->withTrashed()
                    ->where('type', $contactData->type)
                    ->where('value', $value->value())
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
            if ($contactData->type === ContactTypeEnum::EMAIL) {
                $email = (new ContactValue(ContactTypeEnum::EMAIL, $contactData->value))->value();
                $identityIds = $user->canonical()->identityIds();
                $emailAlreadyOwned = Contact::query()
                    ->withTrashed()
                    ->where('user_email_key', $email)
                    ->whereNot(function ($query) use ($identityIds): void {
                        $query->where('contactable_type', 'user')
                            ->whereIn('contactable_id', $identityIds);
                    })
                    ->exists();

                if ($emailAlreadyOwned) {
                    throw new InvalidArgumentException('Этот email уже используется другим пользователем.', previous: $exception);
                }
            }

            throw $exception;
        }
    }
}
