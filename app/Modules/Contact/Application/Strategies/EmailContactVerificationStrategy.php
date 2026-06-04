<?php

namespace App\Modules\Contact\Application\Strategies;

use App\Modules\Contact\Application\Contracts\ContactVerificationStrategy;
use App\Modules\Contact\Application\Exceptions\ContactVerificationCooldownException;
use App\Modules\Contact\Domain\Enums\ContactTypeEnum;
use App\Modules\Contact\Domain\Enums\ContactVerificationChannelEnum;
use App\Modules\Contact\Domain\Enums\ContactVerificationStatusEnum;
use App\Modules\Contact\Domain\Models\Contact;
use App\Modules\Contact\Domain\Models\ContactVerification;
use App\Modules\Contact\Presentation\Mail\ContactVerificationCodeMail;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

class EmailContactVerificationStrategy implements ContactVerificationStrategy
{
    private const RESEND_COOLDOWN_SECONDS = 60;

    public function supports(Contact $contact): bool
    {
        return $contact->type === ContactTypeEnum::EMAIL;
    }

    public function start(Contact $contact): ContactVerification
    {
        $pendingVerification = $contact->verifications()
            ->where('channel', ContactVerificationChannelEnum::EMAIL->value)
            ->where('status', ContactVerificationStatusEnum::PENDING->value)
            ->where('expires_at', '>', now())
            ->latest()
            ->first();

        if ($pendingVerification) {
            $availableAt = $pendingVerification->created_at->copy()->addSeconds(self::RESEND_COOLDOWN_SECONDS);

            if ($availableAt->isFuture()) {
                throw new ContactVerificationCooldownException(now()->diffInSeconds($availableAt));
            }

            $pendingVerification->update([
                'status' => ContactVerificationStatusEnum::CANCELLED,
            ]);
        }

        $code = (string) random_int(100000, 999999);

        $verification = $contact->verifications()->create([
            'channel' => ContactVerificationChannelEnum::EMAIL,
            'status' => ContactVerificationStatusEnum::PENDING,
            'code_hash' => Hash::make($code),
            'sent_to' => $contact->value,
            'attempts_count' => 0,
            'max_attempts' => 5,
            'expires_at' => now()->addMinutes(15),
        ]);

        Mail::to($contact->value)->send(new ContactVerificationCodeMail($verification, $code));

        return $verification;
    }
}
