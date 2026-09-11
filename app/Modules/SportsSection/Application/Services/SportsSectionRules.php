<?php

namespace App\Modules\SportsSection\Application\Services;

use App\Modules\Event\Domain\Enums\GameFormatEnum;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\SportsSection\Domain\Enums\SectionPricingTypeEnum;
use App\Modules\SportsSection\Domain\Exceptions\SportsSectionException;
use App\Modules\Venue\Domain\Models\VenueCourt;

final class SportsSectionRules
{
    public function assertCoach(User $user): User
    {
        $user = $user->canonical();
        if (! $user->isConfirmed() || $user->isBlocked() || $user->trashed()
            || ! $user->hasActiveRole(UserParticipationRoleEnum::COACH->value)) {
            throw new SportsSectionException('Для управления секцией нужен подтверждённый активный аккаунт с ролью тренера.');
        }

        return $user;
    }

    public function assertPlayer(User $user): User
    {
        $user = $user->canonical();
        if (! $user->isConfirmed() || $user->isBlocked() || $user->trashed()
            || ! $user->hasActiveRole(UserParticipationRoleEnum::PLAYER->value)) {
            throw new SportsSectionException('В секцию можно добавить только подтверждённого активного пользователя с ролью игрока.');
        }

        return $user;
    }

    public function assertVenueCourt(?int $venueId, ?int $courtId): void
    {
        if ($courtId === null) {
            return;
        }
        if ($venueId === null || ! VenueCourt::query()->whereKey($courtId)->where('venue_id', $venueId)->exists()) {
            throw new SportsSectionException('Выбранный зал не принадлежит указанной площадке.');
        }
    }

    public function assertPricing(SectionPricingTypeEnum $type, ?int $amountMinor): void
    {
        if ($type === SectionPricingTypeEnum::PAID && ($amountMinor === null || $amountMinor < 1)) {
            throw new SportsSectionException('Для платной секции укажите стоимость одного занятия.');
        }
        if ($type === SectionPricingTypeEnum::FREE && $amountMinor !== null) {
            throw new SportsSectionException('У бесплатной секции стоимость занятия должна быть пустой.');
        }
    }

    public function assertGameFormat(GameFormatEnum $format): void
    {
        if (! in_array($format, [GameFormatEnum::BASKETBALL_5X5, GameFormatEnum::STREETBALL_3X3, GameFormatEnum::STREETBALL_1X1], true)) {
            throw new SportsSectionException('Этот игровой формат недоступен для секции.');
        }
    }
}
