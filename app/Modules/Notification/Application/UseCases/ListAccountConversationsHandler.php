<?php

namespace App\Modules\Notification\Application\UseCases;

use App\Modules\Contract\Domain\Enums\ContractMembershipScopeTypeEnum;
use App\Modules\Contract\Domain\Enums\ContractStatusEnum;
use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Notification\Domain\Enums\UserNotificationStatusEnum;
use App\Modules\Notification\Domain\Models\UserNotification;
use App\Modules\Team\Application\Services\TeamManagementAccess;
use App\Modules\Team\Domain\Enums\TeamInvitationStatusEnum;
use App\Modules\Team\Domain\Enums\TeamPermissionEnum;
use App\Modules\Team\Domain\Models\Team;
use App\Modules\Team\Domain\Models\TeamJoinRequest;
use Illuminate\Support\Collection;

final class ListAccountConversationsHandler
{
    public function __construct(private readonly TeamManagementAccess $teamAccess) {}

    /** @return Collection<int, array<string, mixed>> */
    public function handle(User $user, ?Actor $actor): Collection
    {
        $user = $user->canonical();
        $identityIds = $user->identityIds();

        // Candidate team IDs are resolved from creator ownership and active,
        // accepted permissions. Final access is checked using TeamManagementAccess.
        $managedTeamIds = Team::query()
            ->whereHas('createdByActor', fn ($q) => $q->whereIn('user_id', $identityIds))
            ->orWhereHas('memberships', fn ($q) => $q
                ->whereIn('user_id', $identityIds)
                ->where('invitation_status', TeamInvitationStatusEnum::ACCEPTED->value)
                ->whereHas('contract', fn ($contract) => $contract
                    ->where('status', ContractStatusEnum::ACTIVE->value)
                    ->whereHas('permissions', fn ($permission) => $permission
                        ->where('permission', TeamPermissionEnum::MANAGE_JOIN_REQUESTS->value))))
            ->pluck('id');

        $requests = TeamJoinRequest::query()
            ->where(fn ($q) => $q->whereIn('user_id', $identityIds)->orWhereIn('team_id', $managedTeamIds))
            ->with(['team', 'user.profile', 'messages.sender.profile'])
            ->orderByDesc('updated_at')->get()
            ->filter(fn (TeamJoinRequest $request): bool => $request->team !== null
                && (in_array((int) $request->user_id, $identityIds, true)
                    || ($actor !== null && $this->teamAccess->allows(
                        $request->team, $actor, TeamPermissionEnum::MANAGE_JOIN_REQUESTS
                    ))));

        $notifications = UserNotification::query()
            ->whereIn('user_id', $identityIds)
            ->whereNotNull('payload->join_request_id')
            ->orderByDesc('created_at')->orderByDesc('id')->get()
            ->groupBy(fn (UserNotification $item): int => (int) ($item->payload['join_request_id'] ?? 0));

        $teamConversations = $requests->map(function (TeamJoinRequest $request) use ($notifications): array {
            $items = $notifications->get((int) $request->id, collect());
            $unread = $items->where('status', UserNotificationStatusEnum::NEW);
            $latestMessage = $request->messages->last();
            $preview = $unread->first();
            $date = $preview?->created_at
                ?? $latestMessage?->created_at
                ?? $items->first()?->created_at
                ?? $request->updated_at;

            return [
                'id' => $request->id,
                'name' => 'Заявка в команду «'.$request->team->name.'»',
                'preview' => $preview?->body ?? $latestMessage?->body
                    ?? $items->first()?->body ?? 'Заявка отправлена.',
                'unread_count' => $unread->count(),
                'date' => $date,
                'status' => $request->status->label(),
                'url' => route('account.notifications.threads.team', $request->id),
            ];
        })->values();

        $direct = \App\Modules\Notification\Domain\Models\DirectConversation::query()
            ->where(fn ($q) => $q->whereIn('user_low_id', $identityIds)
                ->orWhereIn('user_high_id', $identityIds))
            ->with(['lowUser.profile', 'highUser.profile', 'latestMessage'])->get();

        $directNotifications = UserNotification::query()
            ->whereIn('user_id', $identityIds)
            ->whereNotNull('payload->direct_conversation_id')
            ->orderByDesc('created_at')->orderByDesc('id')->get()
            ->groupBy(fn (UserNotification $notification): int =>
                (int) ($notification->payload['direct_conversation_id'] ?? 0));

        $directConversations = $direct->map(function ($conversation) use ($identityIds, $directNotifications): array {
            $contact = in_array((int) $conversation->user_low_id, $identityIds, true)
                ? $conversation->highUser : $conversation->lowUser;
            $name = $contact?->username
                ?: (trim(($contact?->profile?->first_name ?? '').' '.($contact?->profile?->last_name ?? ''))
                    ?: 'Участник');
            $notices = $directNotifications->get((int) $conversation->id, collect());
            $unread = $notices->where('status', UserNotificationStatusEnum::NEW);
            $preview = $unread->first();
            return [
                'id' => 'direct-'.$conversation->id,
                'name' => $name,
                'preview' => $preview?->body ?? $conversation->latestMessage?->body ?? 'Личная переписка',
                'unread_count' => $unread->count(),
                'date' => $preview?->created_at ?? $conversation->latestMessage?->created_at ?? $conversation->created_at,
                'status' => null,
                'url' => route('account.notifications.threads.direct', $conversation->id),
            ];
        });

        return $teamConversations->concat($directConversations)->sortByDesc('date')->values();
    }
}
