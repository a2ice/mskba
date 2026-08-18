<?php

namespace App\Modules\Telegram\Application\UseCases;

use App\Modules\Identity\Application\Services\CanonicalUserResolver;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Events\UserFirstLogin;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Telegram\Domain\Models\TelegramAccount;
use App\Modules\Telegram\Infrastructure\Jobs\SyncTelegramProfileAvatarJob;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;

final class CompleteTelegramWebAuthenticationHandler
{
    public function __construct(
        private readonly CanonicalUserResolver $canonicalUserResolver,
    ) {}

    public function handle(User $user, TelegramAccount $telegramAccount): User
    {
        $canonicalUser = $this->canonicalUserResolver->resolve($user);

        if ($user->status === UserStatusEnum::BLOCKED || $canonicalUser->status === UserStatusEnum::BLOCKED) {
            throw new InvalidArgumentException('Аккаунт заблокирован. Обратитесь в поддержку.');
        }

        Auth::login($canonicalUser, true);
        request()->session()->regenerate();

        SyncTelegramProfileAvatarJob::dispatch($telegramAccount->id)->afterResponse();

        $firstLoginMarked = User::query()
            ->whereKey($canonicalUser->id)
            ->whereNull('first_logged_in_at')
            ->update(['first_logged_in_at' => now()]);

        if ($firstLoginMarked === 1) {
            event(new UserFirstLogin((int) $canonicalUser->id));
            $canonicalUser->forceFill(['first_logged_in_at' => now()]);
        }

        return $canonicalUser;
    }
}
