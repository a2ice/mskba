<?php

namespace App\Modules\Event\Application\Services;

use App\Modules\Contract\Domain\Enums\ContractMembershipScopeTypeEnum as Scope;
use App\Modules\Contract\Domain\Models\ContractMembership;
use App\Modules\Event\Domain\Enums\EventResponsibilityPermissionEnum;
use App\Modules\Notification\Application\DTO\CreateUserNotificationDTO;
use App\Modules\Notification\Application\UseCases\CreateUserNotificationHandler;
use App\Modules\Notification\Domain\Enums\UserNotificationStatusEnum;
use App\Modules\Notification\Domain\Enums\UserNotificationTypeEnum;
use App\Modules\Notification\Domain\Models\UserNotification;
use Illuminate\Support\Collection;
use App\Modules\Event\Domain\Enums\GameAdmissionDirectionEnum;
use App\Modules\Event\Domain\Models\Game;
use App\Modules\Event\Domain\Models\GameAdmission;
use App\Modules\Event\Domain\Models\GameInvitationThread;
use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Notification\Domain\Models\RequestThread;
use App\Modules\Team\Application\Services\TeamManagementAccess;
use App\Modules\Team\Domain\Enums\TeamPermissionEnum;
use Illuminate\Support\Facades\DB;

final class GameInvitationConversation
{
    public function __construct(
        private readonly EventManagementAccess $events,
        private readonly TeamManagementAccess $teams,
    ) {}

    /** Called under the parent event and game locks in GameAdmissionService. */
    public function recordInvitation(Game $game, GameAdmission $admission): GameInvitationThread
    {
        $context = GameInvitationThread::query()->firstOrCreate([
            'game_id' => $game->id,
            'team_id' => $admission->team_id,
        ]);
        RequestThread::query()->firstOrCreate([
            'type' => 'game_invitation',
            'subject_id' => $context->id,
        ]);
        $this->changed($context);

        return $context;
    }

    public function canRepresentTeam(GameInvitationThread $context, User $user): bool
    {
        $user = $user->canonical();
        $team = $context->team;
        if ($team === null || $team->trashed() || $user->isBlocked() || $user->trashed()) {
            return false;
        }

        return $this->teams->allows($team, $this->actor($user), TeamPermissionEnum::MANAGE_GAME_PARTICIPATION);
    }

    public function canOrganize(GameInvitationThread $context, User $user): bool
    {
        $user = $user->canonical();
        $event = $context->game?->event;
        if ($event === null || $user->isBlocked() || $user->trashed()) {
            return false;
        }

        return $this->events->allows($event, $this->actor($user), EventResponsibilityPermissionEnum::MANAGE_PARTICIPANTS);
    }

    public function canRead(GameInvitationThread $context, User $user): bool
    {
        return $this->canOrganize($context, $user) || $this->canRepresentTeam($context, $user);
    }

    /** Candidate scopes narrow discovery; the live ACL remains authoritative. */
    public function cards(User $user): Collection
    {
        $user = $user->canonical();
        $ids = $user->identityIds();
        $scopes = ContractMembership::query()->whereIn('user_id', $ids);
        $contexts = GameInvitationThread::query()->where(function ($query) use ($ids, $scopes): void {
            $query->whereIn('team_id', (clone $scopes)->where('scope_type', Scope::TEAM->value)->select('scope_id'))
                ->orWhereHas('team.createdByActor', fn ($q) => $q->whereIn('user_id', $ids))
                ->orWhereHas('game', fn ($q) => $q->whereIn('event_id', (clone $scopes)
                    ->where('scope_type', Scope::EVENT->value)->select('scope_id'))
                    ->orWhereHas('event.organizerActor', fn ($owner) => $owner->whereIn('user_id', $ids)));
        })->with(['game.event', 'team', 'thread.latestMessage'])->latest('updated_at')->limit(100)->get()
            ->filter(fn ($context) => $context->thread && $this->canRead($context, $user));
        $unread = UserNotification::query()->whereIn('user_id', $ids)
            ->where('status', UserNotificationStatusEnum::NEW)
            ->whereIn('payload->game_invitation_thread_id', $contexts->pluck('id'))
            ->get(['id', 'payload'])->groupBy(fn ($notice) => (int) $notice->payload['game_invitation_thread_id']);

        return $contexts->map(function ($context) use ($unread): array {
            $latest = $this->invitations($context)->latest('id')->first();
            $message = $context->thread->latestMessage;

            return [
                'id' => 'game-invitation-'.$context->id,
                'name' => 'Приглашение на игру — '.($context->team?->name ?? 'Команда'),
                'preview' => $message && $message->created_at->gte($latest?->updated_at ?? $context->created_at)
                    ? $message->body : ($context->game?->title ?? 'Игра'),
                'unread_count' => $unread->get($context->id, collect())->count(),
                'date' => $context->updated_at,
                'status' => $context->game?->cancelled_at ? 'Игра отменена' : $this->statusLabel($latest?->status?->value),
                'url' => route('account.notifications.threads.game-invitation', $context),
            ];
        })->values();
    }

    public function statusLabel(?string $status): string
    {
        return match ($status) {
            'pending' => 'Ожидается ответ',
            'accepted' => 'Приглашение принято',
            'declined' => 'Приглашение отклонено',
            'revoked' => 'Приглашение отозвано',
            default => 'Приглашение',
        };
    }

    public function touch(Game $game, GameAdmission $admission): void
    {
        if ($game->uses_event_planning && $admission->team_id !== null) {
            $context = GameInvitationThread::query()->where('game_id', $game->id)->where('team_id', $admission->team_id)->first();
            if ($context !== null) {
                $this->changed($context);
            }
        }
    }

    public function changed(GameInvitationThread $context): void
    {
        $context->touch();
        // Broadcast only invalidation, never private content, after commit.
        DB::afterCommit(static function () use ($context): void {
            try {
                \App\Modules\Notification\Infrastructure\Broadcasting\ConversationChangedBroadcast::dispatch('game_invitation', $context->id);
            } catch (\Throwable $exception) {
                report($exception);
            }
        });
    }

    public function recipients(GameInvitationThread $context): Collection
    {
        $event = $context->game?->event;
        if ($event === null || $context->team === null) {
            return collect();
        }
        $ids = ContractMembership::query()->where(function ($query) use ($event, $context): void {
            $query->where(fn ($q) => $q->where('scope_type', Scope::EVENT->value)->where('scope_id', $event->id))
                ->orWhere(fn ($q) => $q->where('scope_type', Scope::TEAM->value)->where('scope_id', $context->team_id));
        })->pluck('user_id')->push($event->organizerActor()->value('user_id'))
            ->push($context->team->createdByActor()->value('user_id'))->filter()->unique();

        return User::query()->whereIn('id', $ids)->get()->map(fn (User $user) => $user->canonical())
            ->unique('id')->filter(fn (User $user) => $this->canRead($context, $user))->values();
    }

    public function invitations(GameInvitationThread $context)
    {
        return GameAdmission::query()->where('game_id', $context->game_id)
            ->where('team_id', $context->team_id)
            ->where('direction', GameAdmissionDirectionEnum::INVITATION->value);
    }

    /** Invitation status never closes the associated discussion. */
    public function send(GameInvitationThread $context, User $user, string $body): void
    {
        $user = $user->canonical();
        $body = trim($body);
        abort_if($body === '' || mb_strlen($body) > 2000, 422);
        DB::transaction(function () use ($context, $user, $body): void {
            $locked = GameInvitationThread::query()->lockForUpdate()->findOrFail($context->id);
            abort_unless($this->canRead($locked, $user), 403);
            $thread = $locked->thread()->firstOrFail();
            $thread->messages()->create(['sender_user_id' => $user->id, 'body' => $body]);
            $this->changed($locked);
        });
        foreach ($this->recipients($context) as $recipient) {
            if ($recipient->id === $user->id) {
                continue;
            }
            app(CreateUserNotificationHandler::class)->handle(new CreateUserNotificationDTO(
                userId: $recipient->id,
                type: UserNotificationTypeEnum::SYSTEM,
                title: 'Новое сообщение по приглашению на игру',
                body: 'В диалоге о приглашении команды появилось сообщение.',
                actionUrl: route('account.notifications.threads.game-invitation', $context),
                actionText: 'Открыть диалог',
                payload: ['game_invitation_thread_id' => $context->id, 'game_id' => $context->game_id],
            ), true);
        }
    }

    private function actor(User $user): Actor
    {
        return (new Actor(['user_id' => $user->id]))->setRelation('user', $user);
    }
}
