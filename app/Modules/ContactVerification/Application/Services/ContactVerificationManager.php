<?php

namespace App\Modules\ContactVerification\Application\Services;

use App\Modules\Contact\Domain\Models\Contact;
use App\Modules\ContactVerification\Domain\Enums\ContactVerificationPurposeEnum;
use App\Modules\ContactVerification\Domain\Enums\ContactVerificationStatusEnum;
use App\Modules\ContactVerification\Domain\Events\ContactVerificationCompleted;
use App\Modules\ContactVerification\Domain\Models\ContactVerification;

class ContactVerificationManager
{
    public function startForContact(
        Contact $contact,
        ContactVerificationPurposeEnum $purpose,
        ?string $value = null,
        array $payload = [],
    ): ContactVerification {
        return ContactVerification::query()->create([
            'contact_id' => $contact->id,
            'purpose' => $purpose,
            'status' => ContactVerificationStatusEnum::PENDING,
            'value' => $value === null ? null : $this->prepareValue($value),
            'payload' => $payload ?: null,
        ]);
    }

    public function findPendingForContact(
        Contact $contact,
        ContactVerificationPurposeEnum $purpose,
    ): ?ContactVerification {
        return ContactVerification::query()
            ->where('contact_id', $contact->id)
            ->where('purpose', $purpose->value)
            ->where('status', ContactVerificationStatusEnum::PENDING->value)
            ->latest('id')
            ->first();
    }

    public function complete(ContactVerification $verification): void
    {
        if ($verification->status === ContactVerificationStatusEnum::VERIFIED) {
            return;
        }

        $verification->forceFill([
            'status' => ContactVerificationStatusEnum::VERIFIED,
            'verified_at' => now(),
        ])->save();

        event(new ContactVerificationCompleted(
            verificationId: (int) $verification->id,
            contactId: (int) $verification->contact_id,
            purpose: $verification->purpose,
        ));
    }

    private function prepareValue(string $value): string
    {
        /*
         * Development mode: keep verification values readable in the database
         * to simplify local testing of temporary passwords and one-time codes.
         *
         * Production direction: hash this value before storing it, ideally behind
         * an environment-driven verification storage mode, then compare via
         * Hash::check() or a dedicated verifier.
         */
        return $value;
    }
}
