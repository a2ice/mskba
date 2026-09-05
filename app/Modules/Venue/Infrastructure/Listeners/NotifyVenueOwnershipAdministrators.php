<?php

namespace App\Modules\Venue\Infrastructure\Listeners;

use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Notification\Application\DTO\CreateUserNotificationDTO;
use App\Modules\Notification\Application\UseCases\CreateUserNotificationHandler;
use App\Modules\Notification\Domain\Enums\UserNotificationDeliveryCategoryEnum;
use App\Modules\Notification\Domain\Enums\UserNotificationSourceEnum;
use App\Modules\Notification\Domain\Enums\UserNotificationTypeEnum;
use App\Modules\Venue\Domain\Events\VenueOwnershipClaimSubmitted;
use App\Modules\Venue\Domain\Models\VenueOwnershipClaim;

final readonly class NotifyVenueOwnershipAdministrators
{
    public function __construct(private CreateUserNotificationHandler $notifications) {}

    public function handle(VenueOwnershipClaimSubmitted $event): void
    {
        $claim = VenueOwnershipClaim::query()->with(['venue', 'applicant.profile'])->find($event->claimId);
        if ($claim === null) {
            return;
        }

        User::query()
            ->whereNull('canonical_user_id')
            ->where('status', UserStatusEnum::CONFIRMED->value)
            ->whereIn('system_role', [UserSystemRoleEnum::ADMIN->value, UserSystemRoleEnum::SUPERADMIN->value])
            ->pluck('id')
            ->each(function (int $userId) use ($claim): void {
                $this->notifications->handle(new CreateUserNotificationDTO(
                    userId: $userId,
                    type: UserNotificationTypeEnum::SYSTEM,
                    title: 'Новая заявка на управление площадкой',
                    body: "Получена заявка на подтверждение управления площадкой «{$claim->venue->name}».",
                    actionUrl: route('account.venue-ownership.show', $claim, false),
                    actionText: 'Рассмотреть заявку',
                    payload: [
                        'source' => UserNotificationSourceEnum::VENUE_OWNERSHIP_CLAIM_SUBMITTED->value,
                        'delivery_category' => UserNotificationDeliveryCategoryEnum::REQUEST->value,
                        'claim_id' => $claim->public_id,
                        'venue_id' => $claim->venue_id,
                        'audience' => 'administrator',
                    ],
                ));
            });
    }
}
