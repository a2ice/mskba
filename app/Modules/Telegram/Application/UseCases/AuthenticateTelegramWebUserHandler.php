<?php

namespace App\Modules\Telegram\Application\UseCases;

use App\Modules\Identity\Domain\Enums\UserRegistrationChannelEnum;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Events\UserFirstLogin;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Telegram\Application\DTO\TelegramUserIdentityDTO;
use App\Modules\Telegram\Application\Services\TelegramLoginWidgetDataValidator;
use App\Modules\Telegram\Domain\Models\TelegramAccount;
use App\Modules\Telegram\Infrastructure\Jobs\SyncTelegramProfileAvatarJob;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;

final class AuthenticateTelegramWebUserHandler
{
    public function __construct(
        private readonly TelegramLoginWidgetDataValidator $validator,
        private readonly ResolveTelegramUserHandler $resolveTelegramUser,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array{user: User, telegram_account: TelegramAccount, created: bool}
     */
    public function handle(array $payload): array
    {
        $telegramUser = $this->validator->validate($payload);
        $result = $this->resolveTelegramUser->handle(new TelegramUserIdentityDTO(
            id: $telegramUser->id,
            username: $telegramUser->username,
            firstName: $telegramUser->firstName,
            lastName: $telegramUser->lastName,
            languageCode: null,
            photoUrl: $telegramUser->photoUrl,
            rawData: [
                'user' => $telegramUser->rawUser,
                'auth_date' => $telegramUser->authDate,
            ],
            source: 'telegram_web_login',
            registrationChannel: UserRegistrationChannelEnum::TELEGRAM_WEB,
            authenticated: true,
        ));

        if ($result['user']->status === UserStatusEnum::BLOCKED) {
            throw new InvalidArgumentException('Аккаунт заблокирован. Обратитесь в поддержку.');
        }

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
