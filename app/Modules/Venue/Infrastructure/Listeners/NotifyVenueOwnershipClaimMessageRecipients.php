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
use App\Modules\Venue\Domain\Events\VenueOwnershipClaimMessageSent;
use App\Modules\Venue\Domain\Models\VenueOwnershipClaimMessage;

final readonly class NotifyVenueOwnershipClaimMessageRecipients
{
    public function __construct(private CreateUserNotificationHandler $notifications) {}

    public function handle(VenueOwnershipClaimMessageSent $event): void
    {
        $message = VenueOwnershipClaimMessage::query()
            ->with(['authorActor.user', 'conversation.claim.venue', 'conversation.claim.applicant'])
            ->find($event->messageId);
        if ($message === null || $message->authorActor->user === null) {
            return;
        }

        $claim = $message->conversation->claim;
        $author = $message->authorActor->user->canonical();
        $recipients = collect();

        if ($author->isSameIdentity($claim->applicant_user_id)) {
            $recipients = User::query()
                ->whereNull('canonical_user_id')
                ->where('status', UserStatusEnum::CONFIRMED->value)
                ->whereIn('system_role', [UserSystemRoleEnum::ADMIN->value, UserSystemRoleEnum::SUPERADMIN->value])
                ->pluck('id');
        } else {
            $recipients = collect([(int) $claim->applicant->canonical()->id]);
        }

        $recipients->unique()->reject(fn (int $userId): bool => $userId === $author->id)->each(function (int $userId) use ($claim): void {
            $this->notifications->handle(new CreateUserNotificationDTO(
                userId: $userId,
                type: UserNotificationTypeEnum::SYSTEM,
                title: 'Новое сообщение по подтверждению управления',
                body: "В заявке по площадке «{$claim->venue->name}» появилось новое сообщение.",
                actionUrl: route('account.venue-ownership.show', $claim, false).'#ownership-conversation',
                actionText: 'Открыть переписку',
                payload: [
                    'source' => UserNotificationSourceEnum::VENUE_OWNERSHIP_CLAIM_MESSAGE->value,
                    'delivery_category' => UserNotificationDeliveryCategoryEnum::REQUEST->value,
                    'claim_id' => $claim->public_id,
                    'venue_id' => $claim->venue_id,
                ],
            ));
        });
    }
}
