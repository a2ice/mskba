<?php

namespace App\Modules\Telegram\Application\Services;

use App\Modules\Contact\Domain\Enums\ContactTypeEnum;
use App\Modules\Contact\Domain\Models\Contact;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Telegram\Domain\Models\TelegramAccount;

final class TelegramNotificationRecipientResolver
{
    public function resolve(User $user): ?TelegramAccount
    {
        $canonical = $user->canonical();
        $identityIds = $canonical->identityIds();

        $verifiedTelegramIds = Contact::query()
            ->where('contactable_type', 'user')
            ->whereIn('contactable_id', $identityIds)
            ->where('type', ContactTypeEnum::TELEGRAM->value)
            ->whereNotNull('verified_at')
            ->pluck('value')
            ->map(fn ($value): int => (int) $value)
            ->filter(fn (int $value): bool => $value > 0)
            ->unique()
            ->values();

        if ($verifiedTelegramIds->isEmpty()) {
            return null;
        }

        return TelegramAccount::query()
            ->whereIn('user_id', $identityIds)
            ->whereIn('telegram_user_id', $verifiedTelegramIds)
            ->whereNotNull('private_chat_id')
            ->whereNull('private_chat_unavailable_at')
            ->orderByRaw('CASE WHEN user_id = ? THEN 0 ELSE 1 END', [$canonical->id])
            ->orderByDesc('last_auth_at')
            ->orderByDesc('private_chat_available_at')
            ->orderByDesc('updated_at')
            ->first();
    }
}
