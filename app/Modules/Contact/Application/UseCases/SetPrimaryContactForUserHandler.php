<?php

namespace App\Modules\Contact\Application\UseCases;

use App\Modules\Contact\Domain\Models\Contact;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Support\Facades\DB;

class SetPrimaryContactForUserHandler
{
    public function handle(User $user, Contact $contact): Contact
    {
        return DB::transaction(function () use ($user, $contact): Contact {
            $lockedUser = User::query()
                ->whereKey($user->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $ownedContact = $lockedUser->contacts()
                ->whereKey($contact->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($ownedContact->is_primary) {
                return $ownedContact;
            }

            $lockedUser->contacts()
                ->where('is_primary', true)
                ->update(['is_primary' => false]);

            $ownedContact->update(['is_primary' => true]);

            return $ownedContact->refresh();
        });
    }
}
