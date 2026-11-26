<?php

namespace App\Modules\Venue\Application\Services;

use App\Modules\Contact\Domain\Enums\ContactTypeEnum;
use App\Modules\Contact\Domain\Models\Contact;
use App\Modules\Venue\Domain\Models\Venue;

final class VenueRentalContact
{
    /** Resolve at the point of use; never fall back to a public or primary contact. */
    public function resolve(Venue $venue): ?Contact
    {
        $venue = Venue::query()->find($venue->id);
        if ($venue === null || ! $venue->allowsOperationalChanges() || $venue->canonical_venue_id !== null) {
            return null;
        }

        return $venue->contacts()
            ->where('is_rental_contact', true)
            ->where('type', ContactTypeEnum::EMAIL->value)
            ->whereNotNull('verified_at')
            ->first();
    }
}
