<?php

namespace App\Modules\Notification\Presentation\Presenters;

use App\Modules\Contract\Domain\Models\ContractMembership;
use App\Modules\Notification\Domain\Models\UserNotification;
use App\Modules\Team\Domain\Enums\TeamInvitationStatusEnum;

final class UserNotificationPresenter
{
    /** @return array<string, mixed> */
    public function present(UserNotification $notification): array
    {
        return [
            'id' => $notification->id,
            'title' => $notification->title,
            'body' => $notification->body,
            'href' => $notification->action_url ?: route('account.notifications'),
            'read_url' => route('account.notifications.read', $notification),
            'created_at' => $notification->created_at?->toIso8601String(),
            'actions' => $this->actions($notification),
        ];
    }

    /** @return list<array{key: string, label: string, url: string, method: string, variant: string}> */
    private function actions(UserNotification $notification): array
    {
        if (($notification->payload['source'] ?? null) !== 'team.invitation.created') {
            return [];
        }

        $membershipId = filter_var($notification->payload['membership_id'] ?? null, FILTER_VALIDATE_INT);
        if ($membershipId === false) {
            return [];
        }

        $membership = ContractMembership::query()->find($membershipId);
        if ($membership === null
            || (int) $membership->user_id !== (int) $notification->user_id
            || $membership->invitation_status !== TeamInvitationStatusEnum::PENDING) {
            return [];
        }

        $url = route('teams.invitations.respond', $membership->id);

        return [
            ['key' => 'accept', 'label' => 'Принять', 'url' => $url, 'method' => 'PATCH', 'variant' => 'primary'],
            ['key' => 'decline', 'label' => 'Отклонить', 'url' => $url, 'method' => 'PATCH', 'variant' => 'secondary'],
        ];
    }
}
