<?php

namespace App\Modules\Contact\Application\UseCases;

use App\Modules\Contact\Application\DTO\CreateContactDTO;
use App\Modules\Contact\Domain\Models\Contact;
use App\Modules\Identity\Domain\Models\User;

class CreateContactForUserHandler
{
    public function handle(User $user, CreateContactDTO $contactData): Contact
    {
        $is_primary = ! $user->contacts()->exists();

        return $user->contacts()->create([
            'type' => $contactData->type,
            'value' => $contactData->value,
            'label' => $contactData->label,
            'is_primary' => $is_primary,
        ]);
    }
}
