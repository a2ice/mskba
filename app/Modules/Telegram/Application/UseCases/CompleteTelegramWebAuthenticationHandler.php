<?php

namespace App\Modules\Telegram\Application\UseCases;

use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Events\UserFirstLogin;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Telegram\Domain\Models\TelegramAccount;
use App\Modules\Telegram\Infrastructure\Jobs\SyncTelegramProfileAvatarJob;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;

final class CompleteTelegramWebAuthenticationHandler
{
    public function handle(User $user, TelegramAccount $telegramAccount): void
    {
        if ($user->status === UserStatusEnum::BLOCKED) {
            throw new InvalidArgumentException('Аккаунт заблокирован. Обратитесь в поддержку.');
        }

        Auth::login($user, true);
        request()->session()->regenerate();

        SyncTelegramProfileAvatarJob::dispatch($telegramAccount->id)->afterResponse();

        $firstLoginMarked = User::query()
            ->whereKey($user->id)
            ->whereNull('first_logged_in_at')
            ->update(['first_logged_in_at' => now()]);

        if ($firstLoginMarked === 1) {
            event(new UserFirstLogin((int) $user->id));
            $user->forceFill(['first_logged_in_at' => now()]);
        }
    }
}
