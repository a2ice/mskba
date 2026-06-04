<?php

namespace App\Modules\Contact\Application\UseCases;

use App\Modules\Contact\Application\DTO\CreateContactDTO;
use App\Modules\Contact\Domain\Models\Contact;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Contact\Domain\ValueObjects\ContactValue;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class CreateContactForUserHandler
{
    public function handle(User $user, CreateContactDTO $contactData): Contact
    {
        return DB::transaction(function () use ($user, $contactData): Contact {
            $lockedUser = User::query()
                ->whereKey($user->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $value = new ContactValue($contactData->type, $contactData->value);
            $hasActiveContacts = $lockedUser->contacts()->exists();
            $existingContact = $lockedUser->contacts()
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
    }
}
