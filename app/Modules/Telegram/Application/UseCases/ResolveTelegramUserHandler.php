<?php

namespace App\Modules\Telegram\Application\UseCases;

use App\Modules\Contact\Application\UseCases\SyncVerifiedTelegramContactHandler;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Telegram\Application\DTO\TelegramUserIdentityDTO;
use App\Modules\Telegram\Domain\Models\TelegramAccount;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ResolveTelegramUserHandler
{
    public function __construct(
        private readonly SyncVerifiedTelegramContactHandler $syncVerifiedTelegramContact,
    ) {}

    /**
     * @return array{user: User, telegram_account: TelegramAccount, created: bool}
     */
    public function handle(TelegramUserIdentityDTO $identity): array
    {
        return Cache::lock("telegram:user:{$identity->id}", 15)->block(
            5,
            fn (): array => DB::transaction(fn (): array => $this->resolve($identity)),
        );
    }

    /**
     * @return array{user: User, telegram_account: TelegramAccount, created: bool}
     */
    private function resolve(TelegramUserIdentityDTO $identity): array
    {
        $telegramAccount = TelegramAccount::query()
            ->where('telegram_user_id', $identity->id)
            ->lockForUpdate()
            ->first();
        $created = false;

        if ($telegramAccount === null) {
            $user = $this->createUser($identity);
            $telegramAccount = new TelegramAccount([
                'telegram_user_id' => $identity->id,
            ]);
            $telegramAccount->user()->associate($user);
            $created = true;
        } else {
            $user = $telegramAccount->user()->lockForUpdate()->firstOrFail();
        }

        $accountData = [
            'username' => $identity->username,
            'first_name' => $identity->firstName,
            'last_name' => $identity->lastName,
            'language_code' => $identity->languageCode,
            'raw_data' => array_replace($telegramAccount->raw_data ?? [], $identity->rawData, [
                'source' => $identity->source,
            ]),
        ];

        if ($identity->photoUrl !== null) {
            $accountData['photo_url'] = $identity->photoUrl;
        }

        if ($identity->authenticated) {
            $accountData['last_auth_at'] = now();
        }

        $telegramAccount->forceFill($accountData)->save();
        $this->syncVerifiedTelegramContact->handle(
            $user,
            $identity->id,
            $identity->username,
            $identity->firstName,
            $identity->lastName,
            $identity->source,
        );

        return [
            'user' => $user->loadMissing('profile'),
            'telegram_account' => $telegramAccount->refresh(),
            'created' => $created,
        ];
    }

    private function createUser(TelegramUserIdentityDTO $identity): User
    {
        $user = User::query()->create([
            'username' => $this->uniqueUsername($identity->id),
            'password' => null,
            'password_updated_at' => null,
            'is_temporary_password' => false,
            'registration_channel' => $identity->registrationChannel,
            'system_role' => UserSystemRoleEnum::USER,
            'status' => UserStatusEnum::UNCONFIRMED,
        ]);

        $user->createProfile([
            'first_name' => $identity->firstName,
            'last_name' => $identity->lastName,
        ]);

        return $user;
    }

    private function uniqueUsername(int $telegramUserId): string
    {
        $base = "tg_{$telegramUserId}";

        if (! User::query()->where('username', $base)->exists()) {
            return $base;
        }

        do {
            $username = $base.'_'.Str::lower(Str::random(6));
        } while (User::query()->where('username', $username)->exists());

        return $username;
    }
}
