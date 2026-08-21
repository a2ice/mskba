<?php

namespace App\Modules\Vk\Application\UseCases;

use App\Modules\Contact\Application\UseCases\SyncVerifiedVkContactHandler;
use App\Modules\Identity\Domain\Enums\UserRegistrationChannelEnum;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Vk\Application\DTO\VkUserIdentityDTO;
use App\Modules\Vk\Domain\Models\VkAccount;
use App\Modules\Vk\Infrastructure\Jobs\SyncVkProfileAvatarJob;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ResolveVkUserHandler
{
    public function __construct(
        private readonly SyncVerifiedVkContactHandler $syncVerifiedVkContact,
        private readonly SyncVkProfileDataHandler $syncProfileData,
    ) {}

    /** @return array{user: User, vk_account: VkAccount, created: bool} */
    public function handle(VkUserIdentityDTO $identity): array
    {
        return Cache::lock("vk:user:{$identity->id}", 15)->block(5, function () use ($identity): array {
            $result = DB::transaction(fn (): array => $this->resolve($identity));
            $canonicalUser = $result['user']->canonical();

            if (! $canonicalUser->isBlocked()) {
                $this->syncVerifiedVkContact->handle($canonicalUser, $identity, 'vk_id_auth');
                SyncVkProfileAvatarJob::dispatch($result['vk_account']->id)->afterResponse();
            }

            return $result;
        });
    }

    /** @return array{user: User, vk_account: VkAccount, created: bool} */
    private function resolve(VkUserIdentityDTO $identity): array
    {
        $account = VkAccount::query()->where('vk_user_id', $identity->id)->lockForUpdate()->first();
        $created = false;

        if ($account === null) {
            $user = User::query()->create([
                'username' => $this->uniqueUsername($identity->id),
                'password' => null,
                'password_updated_at' => null,
                'is_temporary_password' => false,
                'registration_channel' => UserRegistrationChannelEnum::VK_ID,
                'system_role' => UserSystemRoleEnum::USER,
                'status' => UserStatusEnum::UNCONFIRMED,
            ]);
            $user->createProfile([]);
            $account = new VkAccount(['vk_user_id' => $identity->id]);
            $account->user()->associate($user);
            $created = true;
        } else {
            $user = $account->user()->lockForUpdate()->firstOrFail();
        }

        $account->forceFill([
            'first_name' => $identity->firstName,
            'last_name' => $identity->lastName,
            'avatar_url' => $identity->avatarUrl ?: $account->avatar_url,
            'last_auth_at' => now(),
            'raw_data' => $identity->rawData,
        ])->save();

        if (! $user->canonical()->isBlocked()) {
            $this->syncProfileData->handle($user, $identity);
        }

        return [
            'user' => $user->loadMissing('profile'),
            'vk_account' => $account->refresh(),
            'created' => $created,
        ];
    }

    private function uniqueUsername(string $vkUserId): string
    {
        $safeId = preg_replace('/[^0-9A-Za-z_-]/', '', $vkUserId) ?: 'user';
        $base = "vk_{$safeId}";

        if (! User::query()->where('username', $base)->exists()) {
            return $base;
        }

        do {
            $username = $base.'_'.Str::lower(Str::random(6));
        } while (User::query()->where('username', $username)->exists());

        return $username;
    }
}
