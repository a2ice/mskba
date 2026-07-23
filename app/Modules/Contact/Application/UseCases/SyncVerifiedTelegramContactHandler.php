<?php

namespace App\Modules\Contact\Application\UseCases;

use App\Modules\Contact\Domain\Enums\ContactTypeEnum;
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
        return DB::transaction(function () use ($user, $telegramUserId, $username, $firstName, $lastName, $source): Contact {
            $lockedUser = User::query()
                ->whereKey($user->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $hasActiveContacts = $lockedUser->contacts()->exists();
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
                    'is_primary' => ! $hasActiveContacts,
                    'is_public' => false,
                ]);
                $contact->contactable()->associate($lockedUser);
            } elseif ($wasDeleted) {
                $contact->restore();
            }

            $contact->forceFill([
                'is_primary' => $wasDeleted ? ! $hasActiveContacts : $contact->is_primary,
                'is_public' => $wasDeleted ? false : $contact->is_public,
                'verified_at' => now(),
                'meta' => [
                    'source' => $source,
                    'telegram_user_id' => $telegramUserId,
                    'username' => $username === null ? null : mb_strtolower(ltrim($username, '@')),
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                ],
            ])->save();

            return $contact->refresh();
        });
    }
}
