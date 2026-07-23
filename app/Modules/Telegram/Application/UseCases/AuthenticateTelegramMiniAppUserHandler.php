<?php

namespace App\Modules\Telegram\Application\UseCases;

use App\Modules\Identity\Domain\Enums\UserRegistrationChannelEnum;
use App\Modules\Identity\Domain\Events\UserFirstLogin;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Telegram\Application\DTO\TelegramUserIdentityDTO;
use App\Modules\Telegram\Application\Services\TelegramMiniAppInitDataValidator;
use App\Modules\Telegram\Domain\Models\TelegramAccount;
use App\Modules\Telegram\Infrastructure\Jobs\SyncTelegramProfileAvatarJob;
use Illuminate\Support\Facades\Auth;

final class AuthenticateTelegramMiniAppUserHandler
{
    public function __construct(
        private readonly TelegramMiniAppInitDataValidator $validator,
        private readonly ResolveTelegramUserHandler $resolveTelegramUser,
    ) {}

    /**
     * @return array{user: User, telegram_account: TelegramAccount, created: bool}
     */
    public function handle(string $initData): array
    {
        $telegramUser = $this->validator->validate($initData);

        $result = $this->resolveTelegramUser->handle(new TelegramUserIdentityDTO(
            id: $telegramUser->id,
            username: $telegramUser->username,
            firstName: $telegramUser->firstName,
            lastName: $telegramUser->lastName,
            languageCode: $telegramUser->languageCode,
            photoUrl: $telegramUser->photoUrl,
            rawData: [
                'user' => $telegramUser->rawUser,
                'auth_date' => $telegramUser->authDate,
                'start_param' => $telegramUser->startParam,
                'chat_type' => $telegramUser->chatType,
                'chat_instance' => $telegramUser->chatInstance,
            ],
            source: 'telegram_mini_app',
            registrationChannel: UserRegistrationChannelEnum::TELEGRAM_MINI_APP,
            authenticated: true,
        ));

        Auth::login($result['user'], true);
        request()->session()->regenerate();

        if ($result['telegram_account']->photo_url) {
            SyncTelegramProfileAvatarJob::dispatch($result['telegram_account']->id)->afterResponse();
        }

        $firstLoginMarked = User::query()
            ->whereKey($result['user']->id)
            ->whereNull('first_logged_in_at')
            ->update(['first_logged_in_at' => now()]);

        if ($firstLoginMarked === 1) {
            event(new UserFirstLogin((int) $result['user']->id));
            $result['user']->forceFill(['first_logged_in_at' => now()]);
        }

        return $result;
    }
}
