<?php

namespace App\Modules\Telegram\Application\UseCases;

use App\Modules\Contact\Application\UseCases\SyncVerifiedTelegramContactHandler;
use App\Modules\Identity\Application\Services\UserDuplicateDetector;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Identity\Domain\Models\UserDuplicate;
use App\Modules\Telegram\Application\Services\TelegramLoginWidgetDataValidator;
use App\Modules\Telegram\Domain\Models\TelegramAccount;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final class LinkTelegramIdentityHandler
{
    public function __construct(
        private readonly TelegramLoginWidgetDataValidator $validator,
        private readonly SyncVerifiedTelegramContactHandler $syncVerifiedTelegramContact,
        private readonly UserDuplicateDetector $duplicateDetector,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array{status: string, telegram_account: ?TelegramAccount, duplicate: ?UserDuplicate}
     */
    public function handle(User $currentUser, array $payload): array
    {
        $telegramUser = $this->validator->validate($payload);
        $currentUser = $currentUser->canonical();

        return Cache::lock("telegram:user:{$telegramUser->id}", 15)->block(5, function () use ($currentUser, $telegramUser): array {
            return DB::transaction(function () use ($currentUser, $telegramUser): array {
                $telegramAccount = TelegramAccount::query()
                    ->where('telegram_user_id', $telegramUser->id)
                    ->lockForUpdate()
                    ->first();

                if ($telegramAccount !== null) {
                    $owner = $telegramAccount->user()->lockForUpdate()->firstOrFail();

                    if (! $currentUser->isSameIdentity($owner)) {
                        $candidate = $this->duplicateDetector->observeTelegramConflict(
                            currentUser: $currentUser,
                            telegramOwner: $owner,
                            telegramUserId: (int) $telegramUser->id,
                        );

                        return [
                            'status' => 'duplicate',
                            'telegram_account' => $telegramAccount,
                            'duplicate' => $candidate,
                        ];
                    }
                } else {
                    $telegramAccount = new TelegramAccount([
                        'telegram_user_id' => $telegramUser->id,
                    ]);
                    $telegramAccount->user()->associate($currentUser);
                }

                $telegramAccount->forceFill([
                    'username' => $telegramUser->username,
                    'first_name' => $telegramUser->firstName,
                    'last_name' => $telegramUser->lastName,
                    'photo_url' => $telegramUser->photoUrl ?: $telegramAccount->photo_url,
                    'last_auth_at' => now(),
                    'raw_data' => array_replace($telegramAccount->raw_data ?? [], [
                        'user' => $telegramUser->rawUser,
                        'auth_date' => $telegramUser->authDate,
                        'source' => 'account_telegram_link',
                    ]),
                ])->save();

                $this->syncVerifiedTelegramContact->handle(
                    $currentUser,
                    (int) $telegramUser->id,
                    $telegramUser->username,
                    $telegramUser->firstName,
                    $telegramUser->lastName,
                    'account_telegram_link',
                );

                return [
                    'status' => 'linked',
                    'telegram_account' => $telegramAccount->refresh(),
                    'duplicate' => null,
                ];
            });
        });
    }
}
