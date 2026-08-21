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
            $canonicalUser = $user->canonical();
            $identityIds = $canonicalUser->identityIds();
            User::query()
                ->whereKey($identityIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $ownedContact = $canonicalUser->identityContactsQuery()
                ->whereKey($contact->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $canonicalUser->identityContactsQuery()
                ->where('is_primary', true)
                ->update(['is_primary' => false]);

            $ownedContact->update(['is_primary' => true]);

            return $ownedContact->refresh();
        });
    }
}
