<?php

namespace App\Modules\Venue\Application\Services;

use App\Modules\Contact\Domain\Enums\ContactTypeEnum;
use App\Modules\Contact\Domain\Models\Contact;
use App\Modules\Contact\Domain\ValueObjects\ContactValue;
use App\Modules\Venue\Domain\Models\Venue;
use InvalidArgumentException;

final class PublicVenueContacts
{
    /** @return list<array{type: string, value: string, label: ?string, href: string}> */
    public function forVenue(Venue $venue): array
    {
        if (! $venue->isPubliclyVisible() || $venue->trashed()) {
            return [];
        }

        return $venue->contacts()
            ->where('is_public', true)
            ->whereIn('type', [ContactTypeEnum::EMAIL->value, ContactTypeEnum::PHONE->value])
            ->orderByDesc('is_primary')->orderBy('id')
            ->get(['id', 'type', 'value', 'label'])
            ->map(function (Contact $contact): ?array {
                try {
                    $value = (new ContactValue($contact->type, $contact->value))->value();
                } catch (InvalidArgumentException) {
                    // Legacy malformed values cannot become actionable URLs.
                    return null;
                }

                $href = $contact->type === ContactTypeEnum::EMAIL
                    ? 'mailto:'.str_replace('%40', '@', rawurlencode($value))
                    : 'tel:'.$value;

                return ['type' => $contact->type->value, 'value' => $value, 'label' => $contact->label, 'href' => $href];
            })
            ->filter()->values()->all();
    }
}
