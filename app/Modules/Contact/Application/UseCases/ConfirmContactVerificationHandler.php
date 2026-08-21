<?php

namespace App\Modules\Contact\Application\UseCases;

use App\Modules\Contact\Application\DTO\ConfirmContactVerificationDTO;
use App\Modules\Contact\Application\Exceptions\ContactVerificationException;
use App\Modules\Contact\Domain\Enums\ContactVerificationStatusEnum;
use App\Modules\Contact\Domain\Events\UserContactConfirmed;
use App\Modules\Contact\Domain\Models\Contact;
use App\Modules\Contact\Domain\Models\ContactVerification;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use LogicException;

class ConfirmContactVerificationHandler
{
    public function handle(Contact $contact, ConfirmContactVerificationDTO $verificationData): ContactVerification
    {
        if ($contact->hasBeenVerified()) {
            throw new LogicException('Контакт уже подтвержден.');
        }

        $result = DB::transaction(function () use ($contact, $verificationData): ContactVerification|ContactVerificationException {
            $verification = $contact->verifications()
                ->where('status', ContactVerificationStatusEnum::PENDING->value)
                ->latest()
                ->lockForUpdate()
                ->first();

            if ($verification === null) {
                return ContactVerificationException::noActiveCode();
            }

            if ($verification->expires_at === null || $verification->expires_at->isPast()) {
                $verification->update([
                    'status' => ContactVerificationStatusEnum::EXPIRED,
                ]);

                return ContactVerificationException::expired();
            }

            if ($verification->attempts_count >= $verification->max_attempts) {
                $verification->update([
                    'status' => ContactVerificationStatusEnum::FAILED,
                    'failed_at' => now(),
                ]);

                return ContactVerificationException::attemptsLimitReached();
            }

            if (! Hash::check($verificationData->code, $verification->code_hash)) {
                $attemptsCount = $verification->attempts_count + 1;
                $attemptsLeft = max(0, $verification->max_attempts - $attemptsCount);

                $updates = [
                    'attempts_count' => $attemptsCount,
                ];

                if ($attemptsLeft === 0) {
                    $updates['status'] = ContactVerificationStatusEnum::FAILED;
                    $updates['failed_at'] = now();
                }

                $verification->update($updates);

                if ($attemptsLeft === 0) {
                    return ContactVerificationException::invalidCodeAndAttemptsLimitReached();
                }

                return ContactVerificationException::invalidCode($attemptsLeft);
            }

            $verification->update([
                'status' => ContactVerificationStatusEnum::CONFIRMED,
                'verified_at' => now(),
            ]);

            $contact->update([
                'verified_at' => now(),
            ]);

            return $verification->refresh();
        });

        if ($result instanceof ContactVerificationException) {
            throw $result;
        }

        $contact->refresh();

        if ($contact->contactable_type === 'user') {
            $canonicalUserId = User::query()
                ->find((int) $contact->contactable_id)
                ?->canonical()
                ->getKey() ?? (int) $contact->contactable_id;

            event(new UserContactConfirmed(
                userId: (int) $canonicalUserId,
                contactId: (int) $contact->id,
            ));
        }

        return $result;
    }
}
