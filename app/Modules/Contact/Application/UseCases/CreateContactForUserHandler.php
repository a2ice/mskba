<?php

namespace App\Modules\Contact\Application\UseCases;

use App\Modules\Contact\Application\DTO\CreateContactDTO;
use App\Modules\Contact\Domain\Models\Contact;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Contact\Domain\ValueObjects\ContactValue;
use InvalidArgumentException;

class CreateContactForUserHandler
{
    public function handle(User $user, CreateContactDTO $contactData): Contact
    {
        $contacts = $user->contacts();
        $value = new ContactValue($contactData->type, $contactData->value);

        if ($contacts
            ->where('type', $contactData->type)
            ->where('value', $value->value())
            ->exists()) {
            throw new InvalidArgumentException("Контакт {$value->value()} уже добавлен.");
        }

        // if the user has no contacts yet, set the new contact as primary
        $is_primary = ! $contacts->exists();

        return $contacts->create([
            'type' => $contactData->type,
            'value' => $value->value(),
            'label' => $contactData->label,
            'is_primary' => $is_primary,
        ]);
    }
}
