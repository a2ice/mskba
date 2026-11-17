<?php

namespace App\Modules\Notification\Application\Services;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Notification\Application\DTO\CreateUserNotificationDTO;
use App\Modules\Notification\Application\UseCases\CreateUserNotificationHandler;
use App\Modules\Notification\Domain\Enums\UserNotificationTypeEnum;
use App\Modules\Notification\Domain\Models\RequestThread;
use App\Modules\Notification\Domain\Models\SupportTicket;

final class SupportTicketMessageNotifier
{
    public function __construct(
        private readonly SupportTicketAccess $access,
        private readonly CreateUserNotificationHandler $notifications,
    ) {}

    public function notify(SupportTicket $ticket, RequestThread $thread, User $actor, string $body): void
    {
        $recipients = collect([$ticket->user_id, $thread->lead_user_id])->filter()
            ->unique()->reject(fn ($id) => (int) $id === $actor->id);

        foreach ($recipients as $id) {
            if ((int) $id !== (int) $ticket->user_id) {
                $target = User::query()->find($id);
                if ($target === null || ! $this->access->canRead($target, $ticket, $thread)) {
                    continue;
                }
            }

            $this->notifications->handle(new CreateUserNotificationDTO(
                userId: (int) $id,
                type: UserNotificationTypeEnum::SYSTEM,
                title: 'Новое сообщение техподдержки №'.$ticket->id,
                body: mb_strimwidth(trim($body), 0, 180, '…'),
                actionUrl: route('account.notifications.threads.support', $ticket),
                actionText: 'Открыть переписку',
                payload: ['source' => 'support.message', 'support_ticket_id' => $ticket->id],
            ));
        }
    }
}
