<?php

namespace App\Modules\Contact\Application\UseCases;

use App\Modules\Contact\Domain\Enums\ContactTypeEnum;
use App\Modules\Contact\Domain\Events\UserContactConfirmed;
use App\Modules\Contact\Domain\Models\Contact;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Support\Facades\DB;

final class SyncVerifiedTelegramContactHandler
{
    public function handle(
        User $user,
        int $telegramUserId,
        ?string $username,
        ?string $firstName,
        ?string $lastName,
        string $source = 'telegram_mini_app',
    ): Contact {
        $user = $user->canonical();

        [$contact, $becameVerified] = DB::transaction(function () use ($user, $telegramUserId, $username, $firstName, $lastName, $source): array {
            $lockedUser = User::query()
                ->whereKey($user->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $hasVerifiedPrimaryContact = $lockedUser->identityContactsQuery()
                ->where('is_primary', true)->whereNotNull('verified_at')->exists();
            if (! $hasVerifiedPrimaryContact) {
                $lockedUser->identityContactsQuery()->where('is_primary', true)
                    ->update(['is_primary' => false]);
            }
            $contact = $lockedUser->contacts()
                ->withTrashed()
                ->where('type', ContactTypeEnum::TELEGRAM->value)
                ->where('value', (string) $telegramUserId)
                ->lockForUpdate()
                ->first();
            $wasDeleted = $contact?->trashed() ?? false;

            if ($contact === null) {
                $contact = new Contact([
                    'type' => ContactTypeEnum::TELEGRAM,
                    'value' => (string) $telegramUserId,
                    'is_primary' => ! $hasVerifiedPrimaryContact,
                    'is_public' => false,
                ]);
                $contact->contactable()->associate($lockedUser);
            } elseif ($wasDeleted) {
                $contact->restore();
            }

            $becameVerified = ! $contact->hasBeenVerified();

            $contact->forceFill([
                'is_primary' => ! $hasVerifiedPrimaryContact || (! $wasDeleted && $contact->is_primary),
                'is_public' => $wasDeleted ? false : $contact->is_public,
                'verified_at' => $contact->verified_at ?? now(),
                'meta' => [
                    'source' => $source,
                    'telegram_user_id' => $telegramUserId,
                    'username' => $username === null ? null : mb_strtolower(ltrim($username, '@')),
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                ],
            ])->save();

            return [$contact->refresh(), $becameVerified];
        });

        if ($becameVerified) {
            event(new UserContactConfirmed(
                userId: (int) $user->id,
                contactId: (int) $contact->id,
            ));
        }

        return $contact;
    }
}
