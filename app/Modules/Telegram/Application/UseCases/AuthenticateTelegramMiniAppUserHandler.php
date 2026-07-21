<?php

namespace App\Modules\Telegram\Application\UseCases;

use App\Modules\Contact\Application\UseCases\SyncVerifiedTelegramContactHandler;
use App\Modules\Identity\Domain\Enums\UserRegistrationChannelEnum;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Events\UserFirstLogin;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Telegram\Application\DTO\TelegramMiniAppUserDTO;
use App\Modules\Telegram\Application\Services\TelegramMiniAppInitDataValidator;
use App\Modules\Telegram\Domain\Models\TelegramAccount;
use App\Modules\Telegram\Infrastructure\Jobs\SyncTelegramProfileAvatarJob;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class AuthenticateTelegramMiniAppUserHandler
{
    public function __construct(
        private readonly TelegramMiniAppInitDataValidator $validator,
        private readonly SyncVerifiedTelegramContactHandler $syncVerifiedTelegramContact,
    ) {}

    /**
     * @return array{user: User, telegram_account: TelegramAccount, created: bool}
     */
    public function handle(string $initData): array
    {
        $telegramUser = $this->validator->validate($initData);

        $result = DB::transaction(function () use ($telegramUser): array {
            $telegramAccount = TelegramAccount::query()
                ->where('telegram_user_id', $telegramUser->id)
                ->lockForUpdate()
                ->first();

            $created = false;

            if ($telegramAccount === null) {
                $user = $this->createUser($telegramUser);
                $telegramAccount = new TelegramAccount([
                    'telegram_user_id' => $telegramUser->id,
                ]);
                $telegramAccount->user()->associate($user);
                $created = true;
            } else {
                $user = $telegramAccount->user()->lockForUpdate()->firstOrFail();
            }

            $telegramAccount->forceFill($this->telegramAccountData($telegramUser))->save();
            $this->syncVerifiedTelegramContact->handle(
                $user,
                $telegramUser->id,
                $telegramUser->username,
                $telegramUser->firstName,
                $telegramUser->lastName,
            );

            return [
                'user' => $user->loadMissing('profile'),
                'telegram_account' => $telegramAccount->refresh(),
                'created' => $created,
            ];
        });

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

    private function createUser(TelegramMiniAppUserDTO $telegramUser): User
    {
        $user = User::query()->create([
            'username' => $this->uniqueUsername($telegramUser),
            'password' => null,
            'password_updated_at' => null,
            'is_temporary_password' => false,
            'registration_channel' => UserRegistrationChannelEnum::TELEGRAM_MINI_APP,
            'system_role' => UserSystemRoleEnum::USER,
            'status' => UserStatusEnum::UNCONFIRMED,
        ]);

        $user->createProfile([
            'first_name' => $telegramUser->firstName,
            'last_name' => $telegramUser->lastName,
        ]);

        return $user;
    }

    private function uniqueUsername(TelegramMiniAppUserDTO $telegramUser): string
    {
        $base = 'tg_'.$telegramUser->id;

        if (! User::query()->where('username', $base)->exists()) {
            return $base;
        }

        do {
            $username = $base.'_'.Str::lower(Str::random(6));
        } while (User::query()->where('username', $username)->exists());

        return $username;
    }

    /**
     * @return array<string, mixed>
     */
    private function telegramAccountData(TelegramMiniAppUserDTO $telegramUser): array
    {
        return [
            'username' => $telegramUser->username,
            'first_name' => $telegramUser->firstName,
            'last_name' => $telegramUser->lastName,
            'language_code' => $telegramUser->languageCode,
            'photo_url' => $telegramUser->photoUrl,
            'last_auth_at' => now(),
            'raw_data' => [
                'user' => $telegramUser->rawUser,
                'auth_date' => $telegramUser->authDate,
                'start_param' => $telegramUser->startParam,
                'chat_type' => $telegramUser->chatType,
                'chat_instance' => $telegramUser->chatInstance,
            ],
        ];
    }
}
