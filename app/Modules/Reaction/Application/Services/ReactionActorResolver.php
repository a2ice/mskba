<?php

namespace App\Modules\Reaction\Application\Services;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Reaction\Application\Data\ReactionActor;
use App\Modules\Reaction\Domain\Enums\ReactionActorTypeEnum;
use App\Modules\Telegram\Domain\Models\TelegramAccount;

final class ReactionActorResolver
{
    public function forUser(User $user): ReactionActor
    {
        $user = $user->canonical();

        return new ReactionActor(
            ReactionActorTypeEnum::USER,
            (string) $user->getKey(),
            (int) $user->getKey(),
        );
    }

    public function forTelegramUser(int $telegramUserId): ReactionActor
    {
        $account = TelegramAccount::query()
            ->where('telegram_user_id', $telegramUserId)
            ->first();

        if ($account?->user_id !== null) {
            $user = $account->user?->canonical();

            if ($user === null) {
                return $this->telegramActor($telegramUserId);
            }

            return new ReactionActor(
                ReactionActorTypeEnum::USER,
                (string) $user->id,
                (int) $user->id,
            );
        }

        return $this->telegramActor($telegramUserId);
    }

    public function telegramActor(int $telegramUserId): ReactionActor
    {
        return new ReactionActor(
            ReactionActorTypeEnum::TELEGRAM,
            (string) $telegramUserId,
        );
    }
}
