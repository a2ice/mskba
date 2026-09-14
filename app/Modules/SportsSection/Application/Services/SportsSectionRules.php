<?php

namespace App\Modules\SportsSection\Application\Services;

use App\Modules\Identity\Domain\Enums\UserParticipationRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\SportsSection\Domain\Enums\SectionPricingTypeEnum;
use App\Modules\SportsSection\Domain\Enums\SportsSectionFormatEnum;
use App\Modules\SportsSection\Domain\Enums\TraineeMembershipStatusEnum;
use App\Modules\SportsSection\Domain\Exceptions\SportsSectionException;
use App\Modules\SportsSection\Domain\Models\SportsSection;
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

    public function assertSectionFormat(SportsSectionFormatEnum $format): void
    {
        if (! in_array($format, SportsSectionFormatEnum::cases(), true)) {
            throw new SportsSectionException('Это игровое направление недоступно для секции.');
        }
    }

    public function assertTraineeCapacity(SportsSection $section, bool $alreadyActive = false): void
    {
        if ($alreadyActive || $section->max_trainees === null) {
            return;
        }

        $activeCount = $section->traineeMemberships()
            ->where('status', TraineeMembershipStatusEnum::ACTIVE->value)
            ->count();

        if ($activeCount >= $section->max_trainees) {
            throw new SportsSectionException('В секции сейчас нет свободных мест.');
        }
    }
}
