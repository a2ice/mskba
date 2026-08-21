<?php

namespace App\Modules\Vk\Application\UseCases;

use App\Modules\Contact\Application\UseCases\SyncVerifiedVkContactHandler;
use App\Modules\Identity\Application\Services\UserDuplicateDetector;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Identity\Domain\Models\UserDuplicate;
use App\Modules\Vk\Application\DTO\VkUserIdentityDTO;
use App\Modules\Vk\Domain\Models\VkAccount;
use App\Modules\Vk\Infrastructure\Jobs\SyncVkProfileAvatarJob;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final class LinkVkIdentityHandler
{
    public function __construct(
        private readonly UserDuplicateDetector $duplicateDetector,
        private readonly SyncVerifiedVkContactHandler $syncVerifiedVkContact,
        private readonly SyncVkProfileDataHandler $syncProfileData,
    ) {}

    /** @return array{status: string, vk_account: VkAccount, duplicate: ?UserDuplicate} */
    public function handle(User $currentUser, VkUserIdentityDTO $identity): array
    {
        $currentUser = $currentUser->canonical();

        return Cache::lock("vk:user:{$identity->id}", 15)->block(5, function () use ($currentUser, $identity): array {
            $result = DB::transaction(function () use ($currentUser, $identity): array {
                $account = VkAccount::query()->where('vk_user_id', $identity->id)->lockForUpdate()->first();

                if ($account !== null) {
                    $owner = $account->user()->lockForUpdate()->firstOrFail();

                    if (! $currentUser->isSameIdentity($owner)) {
                        return [
                            'status' => 'duplicate',
                            'vk_account' => $account,
                            'duplicate' => $this->duplicateDetector->observeVkConflict($currentUser, $owner, $identity->id),
                        ];
                    }
                } else {
                    $account = new VkAccount(['vk_user_id' => $identity->id]);
                    $account->user()->associate($currentUser);
                }

                $account->forceFill([
                    'first_name' => $identity->firstName,
                    'last_name' => $identity->lastName,
                    'avatar_url' => $identity->avatarUrl ?: $account->avatar_url,
                    'last_auth_at' => now(),
                    'raw_data' => $identity->rawData,
                ])->save();

                $this->syncProfileData->handle($currentUser, $identity);

                return ['status' => 'linked', 'vk_account' => $account->refresh(), 'duplicate' => null];
            });

            if ($result['status'] === 'linked') {
                $this->syncVerifiedVkContact->handle($currentUser, $identity, 'vk_id_link');
                SyncVkProfileAvatarJob::dispatch($result['vk_account']->id)->afterResponse();
            }

            return $result;
        });
    }
}
