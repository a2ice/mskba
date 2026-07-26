<?php

namespace App\Modules\Telegram\Application\UseCases;

use App\Modules\Identity\Domain\Enums\UserRegistrationChannelEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Telegram\Application\DTO\TelegramUserIdentityDTO;
use App\Modules\Telegram\Application\Services\TelegramLoginWidgetDataValidator;
use App\Modules\Telegram\Domain\Models\TelegramAccount;

final class AuthenticateTelegramWebUserHandler
{
    public function __construct(
        private readonly TelegramLoginWidgetDataValidator $validator,
        private readonly ResolveTelegramUserHandler $resolveTelegramUser,
        private readonly CompleteTelegramWebAuthenticationHandler $completeAuthentication,
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

        $this->completeAuthentication->handle($result['user'], $result['telegram_account']);

        return $result;
    }
}
