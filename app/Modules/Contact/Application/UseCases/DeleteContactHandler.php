<?php

namespace App\Modules\Contact\Application\UseCases;

use App\Modules\Contact\Application\Exceptions\ContactDeletionException;
use App\Modules\Contact\Application\Services\ContactOwnership;
use App\Modules\Contact\Domain\Enums\ContactVerificationStatusEnum;
use App\Modules\Contact\Domain\Models\Contact;
use Illuminate\Support\Facades\DB;

class DeleteContactHandler
{
    public function handle(Contact $contact): void
    {
        // A provisional, unverified primary can be removed. A verified primary
        // must be replaced with another verified contact before deletion.
        if ($contact->is_primary && $contact->hasBeenVerified()) {
            throw ContactDeletionException::primaryContact();
        }

        DB::transaction(function () use ($contact): void {
            if ($contact->contactable_type === 'user') {
                app(ContactOwnership::class)->lock($contact->type, $contact->value);
            }
            $contact = Contact::query()->whereKey($contact->id)->lockForUpdate()->firstOrFail();
            if ($contact->is_primary && $contact->hasBeenVerified()) {
                throw ContactDeletionException::primaryContact();
            }
            $pendingVerificationIds = $contact->verifications()
                ->where('status', ContactVerificationStatusEnum::PENDING->value)
                ->lockForUpdate()
                ->pluck('id');

            $contact->verifications()
                ->whereIn('id', $pendingVerificationIds)
                ->update([
                    'status' => ContactVerificationStatusEnum::CANCELLED,
                ]);

            $contact->forceFill([
                'verified_at' => null,
                'is_public' => false,
            ])->save();

            $contact->delete();
        });
    }
}
