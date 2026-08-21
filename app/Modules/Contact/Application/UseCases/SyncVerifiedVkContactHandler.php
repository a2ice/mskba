<?php

namespace App\Modules\Contact\Application\UseCases;

use App\Modules\Contact\Domain\Enums\ContactTypeEnum;
use App\Modules\Contact\Domain\Events\UserContactConfirmed;
use App\Modules\Contact\Domain\Models\Contact;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Vk\Application\DTO\VkUserIdentityDTO;
use Illuminate\Support\Facades\DB;

final class SyncVerifiedVkContactHandler
{
    public function handle(
        User $user,
        VkUserIdentityDTO $identity,
        string $source = 'vk_id',
    ): Contact {
        [$contact, $becameVerified] = DB::transaction(function () use ($user, $identity, $source): array {
            $lockedUser = User::query()
                ->whereKey($user->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $hasActiveContacts = $lockedUser->contacts()->exists();
            $contact = $lockedUser->contacts()
                ->withTrashed()
                ->where('type', ContactTypeEnum::VK->value)
                ->where('value', $identity->id)
                ->lockForUpdate()
                ->first();
            $wasDeleted = $contact?->trashed() ?? false;

            if ($contact === null) {
                $contact = new Contact([
                    'type' => ContactTypeEnum::VK,
                    'value' => $identity->id,
                    'is_primary' => ! $hasActiveContacts,
                    'is_public' => false,
                ]);
                $contact->contactable()->associate($lockedUser);
            } elseif ($wasDeleted) {
                $contact->restore();
            }

            $becameVerified = ! $contact->hasBeenVerified();

            $contact->forceFill([
                'is_primary' => $wasDeleted ? ! $hasActiveContacts : $contact->is_primary,
                'is_public' => $wasDeleted ? false : $contact->is_public,
                'verified_at' => $contact->verified_at ?? now(),
                'meta' => [
                    'source' => $source,
                    'vk_user_id' => $identity->id,
                    'first_name' => $identity->firstName,
                    'last_name' => $identity->lastName,
                    'avatar_url' => $identity->avatarUrl,
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
