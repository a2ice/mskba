<?php

namespace App\Modules\Finance\Application\Services;

use App\Modules\Finance\Domain\Exceptions\WalletException;
use App\Modules\Identity\Application\Services\SearchDiscoverableUsers;
use App\Modules\Identity\Domain\Models\User;

final readonly class ResolveWalletTransferRecipient
{
    public function __construct(private SearchDiscoverableUsers $discoverableUsers) {}

    public function handle(User $viewer, int $userId): User
    {
        $user = $this->discoverableUsers->findVisibleById($viewer, $userId);

        if ($user === null || ! $user->isConfirmed() || $user->isBlocked()) {
            throw new WalletException('Получатель не найден или недоступен для переводов с учётом его настроек видимости.');
        }

        return $user;
    }
}
