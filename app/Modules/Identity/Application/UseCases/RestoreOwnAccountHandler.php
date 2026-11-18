<?php

namespace App\Modules\Identity\Application\UseCases;

use App\Modules\Contact\Domain\Enums\ContactTypeEnum;
use App\Modules\Contact\Domain\Enums\ContactVerificationChannelEnum;
use App\Modules\Contact\Domain\Enums\ContactVerificationStatusEnum;
use App\Modules\Contact\Domain\Models\Contact;
use App\Modules\Contact\Domain\Models\ContactVerification;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Identity\Presentation\Mail\AccountRestoreCodeMail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

final class RestoreOwnAccountHandler
{
    private const PURPOSE = 'account_restore';
    private const MAX_ATTEMPTS = 5;
    private const CODE_LIFETIME_MINUTES = 10;

    /**
     * Supports previously verified EMAIL contacts only.
     * No account enumeration, SMS fallback, implicit login, or new token issuance.
     */
    public function requestCode(string $email): void
    {
        // Limits apply equally to existing and nonexistent contacts.
        $rateKey = 'account-restore:request:'.hash_hmac('sha256', $email, (string) config('app.key'));
        if (RateLimiter::tooManyAttempts($rateKey, 3)) {
            return;
        }
        RateLimiter::hit($rateKey, 3600);

        $identity = $this->eligibleIdentity($email);
        if ($identity === null || ! $this->canDeliverEmail((string) config('mail.default', 'log'))) {
            return;
        }

        $contact = $identity['contact'];
        $previous = $contact->verifications()
            ->where('channel', ContactVerificationChannelEnum::EMAIL->value)
            ->where('status', ContactVerificationStatusEnum::PENDING->value)
            ->where('meta->purpose', self::PURPOSE)
            ->latest('id')
            ->first();

        // One active code at a time, never more than once per minute.
        if ($previous !== null && $previous->created_at->greaterThan(now()->subMinute())) {
            return;
        }

        $code = (string) random_int(100000, 999999);
        $verification = $contact->verifications()->create([
            'channel' => ContactVerificationChannelEnum::EMAIL,
            'status' => ContactVerificationStatusEnum::PENDING,
            'code_hash' => Hash::make($code),
            'sent_to' => $email,
            'attempts_count' => 0,
            'max_attempts' => self::MAX_ATTEMPTS,
            'expires_at' => now()->addMinutes(self::CODE_LIFETIME_MINUTES),
            'meta' => ['purpose' => self::PURPOSE],
        ]);

        try {
            Mail::to($email)->send(new AccountRestoreCodeMail($code));
        } catch (Throwable $exception) {
            $verification->delete();
            Log::warning('Account restoration code delivery failed', [
                'verification_id' => $verification->id,
                'exception' => $exception::class,
            ]);
            return;
        }

        // Do not revoke a previously delivered code if the new message fails.
        $contact->verifications()
            ->where('status', ContactVerificationStatusEnum::PENDING->value)
            ->where('meta->purpose', self::PURPOSE)
            ->whereKeyNot($verification->id)
            ->update(['status' => ContactVerificationStatusEnum::CANCELLED->value]);
    }

    public function confirm(string $email, string $code): bool
    {
        return DB::transaction(function () use ($email, $code): bool {
            $identity = $this->eligibleIdentity($email);
            if ($identity === null) {
                return false;
            }

            /** @var Contact $contact */
            $contact = $identity['contact'];
            $verification = $contact->verifications()
                ->where('channel', ContactVerificationChannelEnum::EMAIL->value)
                ->where('status', ContactVerificationStatusEnum::PENDING->value)
                ->where('meta->purpose', self::PURPOSE)
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if ($verification === null) {
                return false;
            }

            if ($verification->expires_at === null || $verification->expires_at->isPast()) {
                $verification->update(['status' => ContactVerificationStatusEnum::EXPIRED]);
                return false;
            }

            if ($verification->attempts_count >= self::MAX_ATTEMPTS) {
                $verification->update(['status' => ContactVerificationStatusEnum::FAILED]);
                return false;
            }

            if (! Hash::check($code, (string) $verification->code_hash)) {
                $attempts = $verification->attempts_count + 1;
                $verification->update([
                    'attempts_count' => $attempts,
                    'status' => $attempts >= self::MAX_ATTEMPTS
                        ? ContactVerificationStatusEnum::FAILED
                        : ContactVerificationStatusEnum::PENDING,
                    'failed_at' => $attempts >= self::MAX_ATTEMPTS ? now() : null,
                ]);
                return false;
            }

            /** @var User $canonical */
            $canonical = $identity['canonical'];
            $members = User::withTrashed()
                ->where(function ($query) use ($canonical): void {
                    $query->whereKey($canonical->id)
                        ->orWhere('canonical_user_id', $canonical->id);
                })
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $lockedCanonical = $members->firstWhere('id', $canonical->id);
            if ($lockedCanonical === null || ! $lockedCanonical->trashed()
                || $lockedCanonical->self_deleted_at === null
                || $members->contains(fn (User $user): bool => ! $user->trashed() || $user->isBlocked())) {
                return false;
            }

            // Recheck ownership of the still-verified delivery destination
            // inside the transaction, after acquiring the identity locks.
            if (! Contact::query()
                ->whereKey($contact->id)
                ->where('value', $email)
                ->whereNotNull('verified_at')
                ->exists()) {
                return false;
            }

            $members->each->restore();
            $lockedCanonical->forceFill(['self_deleted_at' => null])->save();

            $verification->update([
                'status' => ContactVerificationStatusEnum::CONFIRMED,
                'verified_at' => now(),
            ]);

            return true;
        });
    }

    /**
     * @return array{canonical: User, contact: Contact}|null
     */
    private function eligibleIdentity(string $email): ?array
    {
        $contacts = Contact::query()
            ->where('contactable_type', 'user')
            ->where('type', ContactTypeEnum::EMAIL->value)
            ->where('value', $email)
            ->whereNotNull('verified_at')
            ->orderBy('id')
            ->get();

        if ($contacts->isEmpty()) {
            return null;
        }

        $ids = $contacts->pluck('contactable_id')->unique()->values()->all();
        $accounts = User::withTrashed()->whereKey($ids)->get();
        if ($accounts->count() !== count($ids)) {
            return null;
        }

        $canonicalIds = $accounts
            ->map(fn (User $user): int => (int) ($user->canonical_user_id ?: $user->id))
            ->unique()
            ->values();
        if ($canonicalIds->count() !== 1) {
            return null;
        }

        $canonical = User::withTrashed()->find($canonicalIds->first());
        if ($canonical === null || ! $canonical->trashed()
            || $canonical->self_deleted_at === null || $canonical->isBlocked()) {
            return null;
        }

        // An admin-only deletion must not be undone through a public endpoint.
        if (User::withTrashed()->where('canonical_user_id', $canonical->id)
            ->whereNull('deleted_at')->exists()) {
            return null;
        }

        return ['canonical' => $canonical, 'contact' => $contacts->first()];
    }

    private function canDeliverEmail(string $mailer): bool
    {
        $transport = (string) config("mail.mailers.{$mailer}.transport", $mailer);
        if (in_array($transport, ['log', 'array', 'null'], true)) {
            return false;
        }
        if (in_array($transport, ['failover', 'roundrobin'], true)) {
            $mailers = (array) config("mail.mailers.{$mailer}.mailers", []);
            return $mailers !== [] && collect($mailers)->every(
                fn ($candidate): bool => $this->canDeliverEmail((string) $candidate),
            );
        }

        return true;
    }
}
