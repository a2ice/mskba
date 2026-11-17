<?php

namespace App\Modules\Contact\Application\UseCases;

use App\Modules\Contact\Domain\Enums\ContactTypeEnum;
use App\Modules\Contact\Domain\Events\UserContactConfirmed;
use App\Modules\Contact\Domain\Models\Contact;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class EmulateSocialContactHandler
{
    public function handle(User $user, string $provider): Contact
    {
        $type = match ($provider) {
            'telegram' => ContactTypeEnum::TELEGRAM,
            'vk' => ContactTypeEnum::VK,
            default => throw new InvalidArgumentException('Недопустимый провайдер.'),
        };
        $canonical = $user->canonical();

        $contact = DB::transaction(function () use ($canonical, $type): Contact {
            $locked = User::query()->whereKey($canonical->id)->lockForUpdate()->firstOrFail();
            $contacts = $locked->contacts()->withTrashed()
                ->where('type', $type->value)
                ->where('value', 'like', 'test_emulate_'.$locked->id.'_%')
                ->get();
            $sequence = $contacts->reduce(function (int $max, Contact $contact) use ($locked): int {
                $prefix = 'test_emulate_'.$locked->id.'_';
                $tail = substr($contact->value, strlen($prefix));

                return ctype_digit($tail) ? max($max, (int) $tail) : $max;
            }, 0) + 1;
            $alias = 'test_emulate_'.str_pad((string) $sequence, 3, '0', STR_PAD_LEFT);
            // The underlying ID can never match a legitimate Telegram/VK numeric ID.
            $syntheticId = 'test_emulate_'.$locked->id.'_'.str_pad((string) $sequence, 3, '0', STR_PAD_LEFT);
            $hasVerifiedPrimary = $locked->identityContactsQuery()
                ->where('is_primary', true)->whereNotNull('verified_at')->exists();
            if (! $hasVerifiedPrimary) {
                $locked->identityContactsQuery()->where('is_primary', true)
                    ->update(['is_primary' => false]);
            }

            return $locked->contacts()->create([
                'type' => $type,
                'value' => $syntheticId,
                'is_primary' => ! $hasVerifiedPrimary,
                'is_public' => false,
                'verified_at' => now(),
                'meta' => [
                    'source' => 'test_provider_emulation',
                    'is_emulated' => true,
                    'test_alias' => $alias,
                    'username' => $type === ContactTypeEnum::TELEGRAM ? $alias : null,
                    'first_name' => $type === ContactTypeEnum::VK ? $alias : null,
                ],
            ]);
        });

        event(new UserContactConfirmed(
            userId: (int) $canonical->id,
            contactId: (int) $contact->id,
        ));

        return $contact;
    }
}
