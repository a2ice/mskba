<?php

namespace App\Modules\Finance\Application\Services;

use App\Modules\Event\Domain\Models\Event;
use App\Modules\Finance\Domain\Enums\WalletOwnerTypeEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Team\Domain\Models\Team;
use App\Modules\Venue\Domain\Models\Venue;

final class WalletOwnerResolver
{
    public function canonicalOwnerId(WalletOwnerTypeEnum $ownerType, int $ownerId): int
    {
        return match ($ownerType) {
            WalletOwnerTypeEnum::USER => (int) User::query()->findOrFail($ownerId)->canonical()->id,
            WalletOwnerTypeEnum::TEAM => (int) Team::query()->findOrFail($ownerId)->id,
            WalletOwnerTypeEnum::EVENT => (int) Event::query()->findOrFail($ownerId)->id,
            WalletOwnerTypeEnum::VENUE => (int) Venue::query()->findOrFail($ownerId)->id,
        };
    }
}
