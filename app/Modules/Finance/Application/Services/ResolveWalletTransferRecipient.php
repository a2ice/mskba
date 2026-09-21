<?php

namespace App\Modules\Finance\Application\Services;

use App\Modules\Finance\Domain\Exceptions\WalletException;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Database\Eloquent\Builder;

final class ResolveWalletTransferRecipient
{
    public function handle(string $identifier): User
    {
        $identifier = ltrim(trim($identifier), '@');
        if ($identifier === '') {
            throw new WalletException('Укажите получателя.');
        }

        $normalized = mb_strtolower($identifier);

        $user = User::query()
            ->where(function (Builder $query) use ($normalized): void {
                $query
                    ->whereRaw('LOWER(nickname) = ?', [$normalized])
                    ->orWhereRaw('LOWER(username) = ?', [$normalized]);
            })
            ->first();

        $user = $user?->canonical();

        if ($user === null || $user->status !== UserStatusEnum::CONFIRMED || $user->isBlocked()) {
            throw new WalletException('Получатель не найден или его аккаунт недоступен для переводов.');
        }

        return $user;
    }
}
