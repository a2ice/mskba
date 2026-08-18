<?php

namespace App\Modules\Telegram\Application\UseCases;

use App\Modules\Identity\Application\Services\CanonicalUserResolver;
use App\Modules\Identity\Domain\Enums\UserRegistrationChannelEnum;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Events\UserFirstLogin;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Telegram\Application\DTO\TelegramUserIdentityDTO;
use App\Modules\Telegram\Application\Services\TelegramMiniAppInitDataValidator;
use App\Modules\Telegram\Domain\Models\TelegramAccount;
use App\Modules\Telegram\Infrastructure\Jobs\SyncTelegramProfileAvatarJob;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;

final class AuthenticateTelegramMiniAppUserHandler
{
    public function __construct(
        private readonly TelegramMiniAppInitDataValidator $validator,
        private readonly ResolveTelegramUserHandler $resolveTelegramUser,
        private readonly CanonicalUserResolver $canonicalUserResolver,
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

        $sourceUser = $result['user'];
        $canonicalUser = $this->canonicalUserResolver->resolve($sourceUser);

        if ($sourceUser->status === UserStatusEnum::BLOCKED || $canonicalUser->status === UserStatusEnum::BLOCKED) {
            throw new InvalidArgumentException('Аккаунт заблокирован. Обратитесь в поддержку.');
        }

        $result['user'] = $canonicalUser;

        Auth::login($canonicalUser, true);
        request()->session()->regenerate();

        SyncTelegramProfileAvatarJob::dispatch($result['telegram_account']->id)->afterResponse();

        $firstLoginMarked = User::query()
            ->whereKey($canonicalUser->id)
            ->whereNull('first_logged_in_at')
            ->update(['first_logged_in_at' => now()]);

        if ($firstLoginMarked === 1) {
            event(new UserFirstLogin((int) $canonicalUser->id));
            $canonicalUser->forceFill(['first_logged_in_at' => now()]);
        }

        return $result;
    }
}
