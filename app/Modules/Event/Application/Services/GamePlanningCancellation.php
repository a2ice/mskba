<?php

namespace App\Modules\Event\Application\Services;

use App\Modules\Event\Domain\Enums\GameStatusEnum;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Event\Domain\Models\Game;
use App\Modules\Event\Domain\Models\GameInvitationRecipient;
use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Notification\Application\DTO\CreateUserNotificationDTO;
use App\Modules\Notification\Application\UseCases\CreateUserNotificationHandler;
use App\Modules\Notification\Domain\Enums\UserNotificationTypeEnum;
use App\Modules\Notification\Domain\Models\UserNotification;

final class GamePlanningCancellation
{
    public function __construct(
        private readonly GameInvitationConversation $conversations,
        private readonly GameAdmissionNotificationStateService $notificationState,
        private readonly CreateUserNotificationHandler $notifications,
    ) {}

    /** Caller holds the event and game locks and has checked deletion permissions. */
    public function cancel(Event $event, Game $game, Actor $actor, bool $notify, ?string $reason): void
    {
        $admissions = $game->admissions()->get();
        $contexts = $game->invitationThreads()->with('team', 'game.event')->get();
        $ids = GameInvitationRecipient::query()->whereIn('game_admission_id', $admissions->pluck('id'))->pluck('user_id')
            ->merge(UserNotification::query()->where('payload->game_id', $game->id)
                ->whereNotNull('payload->game_admission_id')->whereIn('payload->game_admission_id', $admissions->pluck('id'))
                ->pluck('user_id'))
            ->merge($admissions->pluck('user_id'))
            ->merge($game->rosterEntries()->where('status', '!=', 'excluded')->pluck('user_id'));
        foreach ($contexts as $context) {
            $ids = $ids->merge($this->conversations->recipients($context)
                ->filter(fn (User $user) => $this->conversations->canRepresentTeam($context, $user))->pluck('id'));
            $context->touch();
        }

        // Cancellation is a separate historical event: keep the original answers
        // (including declined and accepted) intact, but close their actionable state.
        $reason = trim((string) $reason);
        $game->forceFill([
            'status' => GameStatusEnum::CANCELLED,
            'accepts_applications' => false,
            'cancelled_at' => now(),
            'cancelled_by_actor_id' => $actor->id,
            'cancellation_reason' => $reason === '' ? null : $reason,
        ])->save();
        foreach ($admissions as $admission) {
            $this->notificationState->resolve($admission);
        }

        if ($notify) {
            $users = User::query()->whereIn('id', $ids->filter()->unique())->get()
                ->map(fn (User $user) => $user->canonical())
                ->unique('id')->filter(fn (User $user) => !$user->trashed() && !$user->isBlocked());
            foreach ($users as $user) {
                if ($user->id === $actor->user?->canonical()->id) {
                    continue;
                }
                $context = $contexts->first(fn ($item) => $this->conversations->canRepresentTeam($item, $user));
                $this->notifications->handle(new CreateUserNotificationDTO(
                    userId: $user->id,
                    type: UserNotificationTypeEnum::SYSTEM,
                    title: 'Игра отменена',
                    body: 'Игра «'.$game->title.'» в мероприятии «'.$event->title.'» отменена.'
                        .($reason === '' ? '' : "\nПричина: ".$reason),
                    actionUrl: $context ? route('account.notifications.threads.game-invitation', $context)
                        : route('events.show', $event->routeIdentifier()),
                    actionText: $context ? 'Открыть диалог' : 'Открыть мероприятие',
                    payload: ['source' => 'game.planning.cancelled', 'game_id' => $game->id, 'event_id' => $event->id,
                        ...($context ? ['game_invitation_thread_id' => $context->id] : [])],
                ), true);
            }
        }

        // Archive rather than hard-delete: invitations, periods and threads remain intact.
        $game->delete();
    }
}
