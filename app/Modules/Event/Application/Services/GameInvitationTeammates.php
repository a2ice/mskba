<?php

namespace App\Modules\Event\Application\Services;

use App\Modules\Contract\Domain\Enums\ContractStatusEnum;
use App\Modules\Event\Domain\Enums\EventStatusEnum;
use App\Modules\Event\Domain\Enums\GameStatusEnum;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Event\Domain\Models\Game;
use App\Modules\Event\Domain\Models\GameAdmission;
use App\Modules\Event\Domain\Models\GameInvitationRecipient;
use App\Modules\Event\Domain\Models\GameInvitationThread;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Notification\Application\DTO\CreateUserNotificationDTO;
use App\Modules\Notification\Application\UseCases\CreateUserNotificationHandler;
use App\Modules\Notification\Domain\Enums\UserNotificationTypeEnum;
use App\Modules\Team\Domain\Enums\TeamInvitationStatusEnum;
use App\Modules\Team\Domain\Enums\TeamMemberTypeEnum;
use App\Modules\Team\Domain\Models\Team;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class GameInvitationTeammates
{
    public function __construct(
        private readonly GameInvitationConversation $conversations,
        private readonly CreateUserNotificationHandler $notifications,
    ) {}

    public function eligible(GameInvitationThread $context, User $viewer): Collection
    {
        abort_unless($this->conversations->canRepresentTeam($context, $viewer), 403);

        return $this->players($context->team);
    }

    private function players(Team $team): Collection
    {
        return $team->memberships()->where('invitation_status', TeamInvitationStatusEnum::ACCEPTED->value)
            ->withSportRole(TeamMemberTypeEnum::PLAYER)
            ->whereHas('contract', fn ($query) => $query->where('status', ContractStatusEnum::ACTIVE->value))
            ->with('user.profile')->get()->pluck('user')->filter()
            ->map(fn (User $user) => $user->canonical())
            ->filter(fn (User $user) => !$user->trashed() && $user->status === UserStatusEnum::CONFIRMED)
            ->unique('id')->sortBy('username')->values();
    }

    public function available(GameInvitationThread $context, GameAdmission $admission): bool
    {
        $game = $context->game;
        return $game !== null && !$game->trashed()
            && $game->hasSportConfiguration()
            && $game->status === GameStatusEnum::SCHEDULED
            && $game->actual_started_at === null
            && $game->event?->status === EventStatusEnum::PUBLISHED
            && !$game->event?->ends_at?->isPast()
            && $admission->status->isActive();
    }

    public function alreadyNotified(GameAdmission $admission): array
    {
        return GameInvitationRecipient::query()->where('game_admission_id', $admission->id)
            ->pluck('user_id')->map(fn ($id) => (int) $id)->all();
    }

    /** Serialize with admission responses, cancellation and team membership changes. */
    public function send(GameInvitationThread $context, int $admissionId, User $viewer, array $userIds, ?string $comment): int
    {
        $viewer = $viewer->canonical();
        abort_unless($this->conversations->canRepresentTeam($context, $viewer), 403);
        $game = $context->game;
        abort_if($game === null || $game->trashed(), 422);

        return DB::transaction(function () use ($context, $game, $viewer, $admissionId, $userIds, $comment): int {
            $event = Event::query()->lockForUpdate()->findOrFail($game->event_id);
            $lockedGame = Game::query()->lockForUpdate()->findOrFail($game->id);
            $admission = $this->conversations->invitations($context)->whereKey($admissionId)->lockForUpdate()->firstOrFail();
            $team = Team::query()->lockForUpdate()->findOrFail($context->team_id);
            $context->setRelation('game', $lockedGame->setRelation('event', $event))->setRelation('team', $team);
            abort_unless($this->conversations->canRepresentTeam($context, $viewer), 403);
            if (!$this->available($context, $admission)) {
                throw ValidationException::withMessages(['recipients' => 'Приглашение больше не активно.']);
            }
            $eligible = $this->players($team)->keyBy('id');
            $selected = collect($userIds)->map(fn ($id) => (int) $id)->unique();
            if ($selected->isEmpty() || $selected->contains(fn ($id) => !$eligible->has($id))) {
                throw ValidationException::withMessages(['recipients' => 'Выберите действующих игроков команды. Обновите список.']);
            }
            $comment = trim((string) $comment);
            $sent = 0;
            foreach ($selected as $userId) {
                $recipient = GameInvitationRecipient::query()->firstOrCreate([
                    'game_admission_id' => $admission->id, 'user_id' => $userId,
                ], ['notified_by_user_id' => $viewer->id, 'comment' => $comment ?: null]);
                if (!$recipient->wasRecentlyCreated) {
                    continue;
                }
                $opponent = (int) $lockedGame->planned_team_a_id === (int) $team->id
                    ? $lockedGame->plannedTeamB : $lockedGame->plannedTeamA;
                $body = 'Команда «'.$team->name.'» приглашена на игру «'.$lockedGame->title.'».'
                    .($opponent ? ' Соперник: '.$opponent->name.'.' : '')
                    .($event->starts_at ? ' '.$event->starts_at->timezone('Europe/Moscow')->format('d.m.Y H:i').' (МСК).' : '')
                    .($event->venue ? ' Площадка: '.$event->venue->name.'.' : '')
                    .' Это оповещение, а не подтверждение вашего участия.'
                    .($comment !== '' ? "\n".$comment : '');
                $this->notifications->handle(new CreateUserNotificationDTO(
                    userId: $userId,
                    type: UserNotificationTypeEnum::SYSTEM,
                    title: 'Предстоящая игра вашей команды',
                    body: $body,
                    actionUrl: route('events.show', $event->routeIdentifier()).'#planned-game-'.$lockedGame->id,
                    actionText: 'Открыть мероприятие',
                    payload: ['game_id' => $lockedGame->id, 'event_id' => $event->id,
                        'team_id' => $team->id, 'game_invitation_recipient_id' => $recipient->id],
                ), true);
                $sent++;
            }
            $this->conversations->changed($context);

            return $sent;
        }, 3);
    }
}
