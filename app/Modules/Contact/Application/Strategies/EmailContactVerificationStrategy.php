<?php

namespace App\Modules\Contact\Application\Strategies;

use App\Modules\Contact\Application\Contracts\ContactVerificationStrategy;
use App\Modules\Contact\Application\Exceptions\ContactVerificationCooldownException;
use App\Modules\Contact\Application\Exceptions\ContactVerificationDeliveryException;
use App\Modules\Contact\Domain\Enums\ContactTypeEnum;
use App\Modules\Contact\Domain\Enums\ContactVerificationChannelEnum;
use App\Modules\Contact\Domain\Enums\ContactVerificationStatusEnum;
use App\Modules\Contact\Domain\Models\Contact;
use App\Modules\Contact\Domain\Models\ContactVerification;
use App\Modules\Contact\Presentation\Mail\ContactVerificationCodeMail;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use Throwable;

class EmailContactVerificationStrategy implements ContactVerificationStrategy
{
    public const RESEND_COOLDOWN_SECONDS = 60;

    public static function secondsUntilResend(?ContactVerification $verification): int
    {
        if ($verification === null || $verification->expires_at === null || $verification->expires_at->isPast()) {
            return 0;
        }

        return max(0, $verification->created_at->timestamp
            + self::RESEND_COOLDOWN_SECONDS - now()->timestamp);
    }

    private function containsSinkTransport(string $mailer): bool
    {
        foreach ((array) config("mail.mailers.{$mailer}.mailers", []) as $fallback) {
            $fallback = (string) $fallback;
            $transport = (string) config("mail.mailers.{$fallback}.transport", $fallback);
            if (in_array($transport, ['log', 'array', 'null'], true)) {
                return true;
            }
            if ($transport === 'failover' && $this->containsSinkTransport($fallback)) {
                return true;
            }
        }

        return false;
    }

    public function supports(Contact $contact): bool
    {
        return $contact->type === ContactTypeEnum::EMAIL;
    }

    public function start(Contact $contact): ContactVerification
    {
        // A log/array transport does not deliver mail. Fail before creating a
        // token or cancelling an existing valid one, so no phantom cooldown.
        $mailer = (string) config('mail.default', 'log');
        $transport = (string) config("mail.mailers.{$mailer}.transport", $mailer);
        if (in_array($transport, ['log', 'array', 'null'], true)
            || ($transport === 'failover' && $this->containsSinkTransport($mailer))) {
            throw ContactVerificationDeliveryException::unavailable();
        }

        $pendingVerification = $contact->verifications()
            ->where('channel', ContactVerificationChannelEnum::EMAIL->value)
            ->where('status', ContactVerificationStatusEnum::PENDING->value)
            ->where('expires_at', '>', now())
            ->latest()
            ->first();

        if ($pendingVerification) {
            $secondsLeft = self::secondsUntilResend($pendingVerification);

            if ($secondsLeft > 0) {
                throw new ContactVerificationCooldownException($secondsLeft);
            }

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

        try {
            Mail::to($contact->value)->send(new ContactVerificationCodeMail($verification, $code));
        } catch (Throwable $exception) {
            // The SMTP transport rejected the request. Do not leave a token
            // that looks sent or starts a 60-second resend cooldown.
            $verification->delete();
            Log::warning('Contact verification email submission failed', [
                'contact_id' => $contact->id,
                'exception' => $exception::class,
            ]);
            throw ContactVerificationDeliveryException::failed();
        }

        // Only invalidate the older code after the replacement was accepted.
        if ($pendingVerification) {
            $pendingVerification->update(['status' => ContactVerificationStatusEnum::CANCELLED]);
        }

        return $verification;
    }
}
