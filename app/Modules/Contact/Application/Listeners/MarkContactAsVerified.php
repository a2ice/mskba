<?php

namespace App\Modules\Contact\Application\Listeners;

use App\Modules\Contact\Domain\Enums\ContactStatusEnum;
use App\Modules\Contact\Domain\Models\Contact;
use App\Modules\ContactVerification\Domain\Events\ContactVerificationCompleted;

class MarkContactAsVerified
{
    public function handle(ContactVerificationCompleted $event): void
    {
        $contact = Contact::query()->find($event->contactId);

        if ($contact === null || $contact->status === ContactStatusEnum::VERIFIED) {
            return;
        }

        $contact->forceFill([
            'status' => ContactStatusEnum::VERIFIED,
        ])->save();
    }
}
