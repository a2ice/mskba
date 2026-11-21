<?php

namespace App\Modules\Contact\Application\Services;

use App\Modules\Contact\Domain\Enums\ContactTypeEnum;
use App\Modules\Contact\Domain\Enums\ContactVerificationStatusEnum;
use App\Modules\Contact\Domain\Models\Contact;
use App\Modules\Contact\Domain\ValueObjects\ContactValue;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class ContactOwnership
{
    // Acquire before identity/contact row locks. A PostgreSQL transaction lock is
    // shared by creation, resend, confirmation and invitation acceptance.
    public function lock(ContactTypeEnum $type, string $value): void
    {
        $this->lockMany([[$type, $value]]);
    }

    public function lockMany(array $contacts): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        $keys = [];
        foreach ($contacts as [$type, $value]) {
            if ($this->supports($type)) {
                $keys[] = 'contact-ownership:'.$type->value.':'.(new ContactValue($type, $value))->value();
            }
        }
        $keys = array_unique($keys);
        sort($keys, SORT_STRING);
        foreach ($keys as $key) {
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [$key]);
        }
    }

    public function supports(ContactTypeEnum $type): bool
    {
        return in_array($type, [ContactTypeEnum::EMAIL, ContactTypeEnum::PHONE], true);
    }

    /** @param list<int> $identityIds */
    public function assertAvailable(ContactTypeEnum $type, string $value, array $identityIds): void
    {
        if (! $this->supports($type)) {
            return;
        }
        $normalized = (new ContactValue($type, $value))->value();
        $query = Contact::query()->withTrashed()
            ->where('contactable_type', 'user')
            ->where('type', $type->value)
            ->whereNotIn('contactable_id', $identityIds);
        if ($type === ContactTypeEnum::EMAIL) {
            $query->where('user_email_key', $normalized);
        } else {
            $query->where('user_phone_key', $normalized)
                ->where(fn ($query) => $query->whereNotNull('verified_at')->orWhere('ownership_reserved', true));
        }
        if ($query->exists()) {
            throw new InvalidArgumentException($type === ContactTypeEnum::EMAIL
                ? 'Этот email уже используется другим пользователем.'
                : 'Этот телефон уже используется другим пользователем.');
        }
    }

    // Only call after proof of possession, in the caller's locked transaction.
    public function claim(Contact $contact): void
    {
        if ($contact->contactable_type !== 'user' || ! $this->supports($contact->type)) {
            $contact->update(['verified_at' => now()]);
            return;
        }
        $user = User::query()->findOrFail($contact->contactable_id)->canonical();
        $identityIds = $user->identityIds();
        $this->assertAvailable($contact->type, $contact->value, $identityIds);

        $value = (new ContactValue($contact->type, $contact->value))->value();
        // A pending alias may have survived an earlier account merge.
        // Never create a second verified row for the same reserved email.
        if ($contact->type === ContactTypeEnum::EMAIL && Contact::query()->withTrashed()
            ->where('user_email_key', $value)->whereKeyNot($contact->id)->exists()) {
            throw new InvalidArgumentException('Этот email уже подтверждён в вашем аккаунте.');
        }

        // The email unique index is the final safeguard even for direct writes.
        $contact->update(['verified_at' => now()]);
        $others = Contact::query()->where('contactable_type', 'user')
            ->where('type', $contact->type->value)->whereKeyNot($contact->id)
            ->whereNull('verified_at')->where('ownership_reserved', false)
            ->where($contact->type === ContactTypeEnum::EMAIL ? 'value' : 'user_phone_key', $value)
            ->orderBy('id')->lockForUpdate()->get();
        foreach ($others as $other) {
            $other->verifications()->where('status', ContactVerificationStatusEnum::PENDING->value)
                ->update(['status' => ContactVerificationStatusEnum::CANCELLED->value]);
            $other->update(['is_primary' => false, 'is_public' => false]);
            $other->delete();
        }
    }
}
