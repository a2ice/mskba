<?php

namespace App\Modules\Notification\Application\UseCases;

use App\Modules\Contract\Domain\Enums\ContractStatusEnum;
use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Notification\Application\Services\ProfileRequestThreadAccess;
use App\Modules\Notification\Application\Services\SupportTicketAccess;
use App\Modules\Notification\Domain\Enums\UserNotificationStatusEnum;
use App\Modules\Notification\Domain\Models\DirectConversation;
use App\Modules\Notification\Domain\Models\SupportTicket;
use App\Modules\Notification\Domain\Models\UserNotification;
use App\Modules\Notification\Presentation\Presenters\SupportMessagePresenter;
use App\Modules\Team\Application\Services\TeamManagementAccess;
use App\Modules\Team\Domain\Enums\TeamInvitationStatusEnum;
use App\Modules\Team\Domain\Enums\TeamPermissionEnum;
use App\Modules\Team\Domain\Models\Team;
use App\Modules\Team\Domain\Models\TeamJoinRequest;
use Illuminate\Support\Collection;

final class ListAccountConversationsHandler
{
    public function __construct(
        private readonly TeamManagementAccess $teamAccess,
        private readonly ProfileRequestThreadAccess $profileAccess,
        private readonly SupportTicketAccess $supportAccess,
    ) {}

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
            $latestNotice = $items->first();
            // An old unread notification must not hide a newer reply (sent or received).
            $messageIsNewest = $latestMessage !== null && ($latestNotice === null
                || $latestMessage->created_at->greaterThanOrEqualTo($latestNotice->created_at));
            $date = $messageIsNewest ? $latestMessage->created_at
                : ($latestNotice?->created_at ?? $request->updated_at);

            return [
                'id' => $request->id,
                'name' => 'Заявка в команду «'.$request->team->name.'»',
                'preview' => $messageIsNewest ? $latestMessage->body
                    : ($latestNotice?->body ?? 'Заявка отправлена.'),
                'unread_count' => $unread->count(),
                'date' => $date,
                'status' => $request->status->label(),
                'url' => route('account.notifications.threads.team', $request->id),
            ];
        })->values();

        $direct = DirectConversation::query()
            ->where(fn ($q) => $q->whereIn('user_low_id', $identityIds)
                ->orWhereIn('user_high_id', $identityIds))
            ->with(['lowUser.profile', 'highUser.profile', 'latestMessage'])->get();

        $directNotifications = UserNotification::query()
            ->whereIn('user_id', $identityIds)
            ->whereNotNull('payload->direct_conversation_id')
            ->orderByDesc('created_at')->orderByDesc('id')->get()
            ->groupBy(fn (UserNotification $notification): int => (int) ($notification->payload['direct_conversation_id'] ?? 0));

        $directConversations = $direct->map(function ($conversation) use ($identityIds, $directNotifications): array {
            $contact = in_array((int) $conversation->user_low_id, $identityIds, true)
                ? $conversation->highUser : $conversation->lowUser;
            $name = $contact?->username
                ?: (trim(($contact?->profile?->first_name ?? '').' '.($contact?->profile?->last_name ?? ''))
                    ?: 'Участник');
            $notices = $directNotifications->get((int) $conversation->id, collect());
            $unread = $notices->where('status', UserNotificationStatusEnum::NEW);
            $preview = $unread->first();
            $lastMessage = $conversation->latestMessage;
            $author = $lastMessage && in_array((int) $lastMessage->sender_user_id, $identityIds, true)
                ? 'Вы' : $name;

            return [
                'id' => 'direct-'.$conversation->id,
                'name' => $name,
                'preview' => $lastMessage ? $author.': '.$lastMessage->body : ($preview?->body ?? 'Личная переписка'),
                'unread_count' => $unread->count(),
                'date' => $lastMessage?->created_at ?? $notices->first()?->created_at ?? $conversation->created_at,
                'status' => null,
                'url' => route('account.notifications.threads.direct', $conversation->id),
            ];
        });

        // Correlated EXISTS on (thread, member) instead of multiplying rows with large JOINs.
        $profileRequests = $this->profileAccess->visibleRequests($user)
            ->with(['thread' => fn ($q) => $q->with(['memberships' => fn ($m) => $m->where('user_id', $user->id), 'latestMessage.sender'])])
            ->latest('id')->limit(100)->get();
        $profileUnread = UserNotification::query()
            ->whereIn('user_id', $identityIds)
            ->where('status', UserNotificationStatusEnum::NEW)
            ->whereIn('payload->profile_change_request_id', $profileRequests->pluck('id'))
            ->get(['id', 'payload'])
            ->groupBy(fn (UserNotification $n) => (int) ($n->payload['profile_change_request_id'] ?? 0));
        $afterId = $this->profileAccess->eligibleAfterId($user);
        $profileConversations = $profileRequests->map(function ($profile) use ($user, $afterId, $profileUnread): array {
            $member = $profile->thread?->memberships->firstWhere('user_id', $user->id);
            $open = $profile->user_id === $user->id || ($afterId !== null
                && $member?->removed_at === null
                && ($member !== null || $profile->id > $afterId));
            $lastMessage = $open ? $profile->thread?->latestMessage : null;
            $author = $lastMessage ? ((int) $lastMessage->sender_user_id === (int) $user->id
                ? 'Вы' : ($lastMessage->sender?->username ?? 'Участник')) : null;
            $status = $open ? match ($profile->status) {
                'pending' => 'На рассмотрении', 'approved' => 'Одобрена',
                'rejected' => 'Отклонена', 'blocked' => 'Заблокирована',
                'cancelled' => 'Отменена', default => 'Заявка',
            } : 'Доступ прекращён';

            return [
                'id' => 'profile-'.$profile->id,
                'name' => 'Изменение данных №'.$profile->id,
                'preview' => $open
                    ? ($lastMessage ? $author.': '.$lastMessage->body : 'Заявка отправлена на рассмотрение.')
                    : ($member?->removal_reason === 'excluded' ? 'Ты был исключён из рассмотрения заявки.'
                        : 'Доступ к обсуждению заявки прекращён.'),
                'unread_count' => $open ? $profileUnread->get($profile->id, collect())->count() : 0,
                'date' => $lastMessage?->created_at ?? $profile->updated_at,
                'status' => $status,
                'url' => route('account.notifications.threads.profile', $profile->id),
            ];
        });

        $tickets = $this->supportAccess->visible($user)
            ->with(['user', 'thread' => fn ($q) => $q->with('latestMessage.sender')])
            ->orderByDesc('id')->limit(100)->get();
        $supportUnread = UserNotification::query()->whereIn('user_id', $identityIds)
            ->where('status', UserNotificationStatusEnum::NEW)
            ->whereIn('payload->support_ticket_id', $tickets->pluck('id'))
            ->get(['id', 'payload'])
            ->groupBy(fn (UserNotification $notice) => (int) ($notice->payload['support_ticket_id'] ?? 0));
        $supportMessagePresenter = app(SupportMessagePresenter::class);
        $supportConversations = $tickets->map(function (SupportTicket $ticket) use ($user, $supportUnread, $supportMessagePresenter): array {
            $last = $ticket->thread?->latestMessage;
            $author = $last ? ((int) $last->sender_user_id === $user->id
                ? 'Вы' : ($last->sender?->username ?? 'Техподдержка')) : null;

            return [
                'id' => 'support-'.$ticket->id,
                'name' => 'Техподдержка №'.$ticket->id.' — '.$ticket->subject,
                'preview' => $last ? $author.': '.$supportMessagePresenter->preview($last->body) : 'Обращение в техподдержку',
                'unread_count' => $supportUnread->get($ticket->id, collect())->count(),
                'date' => $last?->created_at ?? $ticket->created_at,
                'status' => $ticket->status === 'open' ? 'Открыто' : 'Завершено',
                'url' => route('account.notifications.threads.support', $ticket),
            ];
        });

        return $teamConversations->concat($directConversations)
            ->concat($profileConversations)->concat($supportConversations)
            ->sortByDesc('date')->values();
    }
}
