<?php

namespace App\Modules\Event\Application\Services;

use App\Modules\Event\Domain\Enums\EventParticipantRoleEnum;
use App\Modules\Event\Domain\Enums\EventParticipantStatusEnum;
use App\Modules\Event\Domain\Enums\EventResponsibilityPermissionEnum;
use App\Modules\Event\Domain\Enums\EventStatusEnum;
use App\Modules\Event\Domain\Enums\EventTypeEnum;
use App\Modules\Event\Domain\Enums\EventVisibilityEnum;
use App\Modules\Event\Domain\Enums\GameAdmissionCandidateTypeEnum;
use App\Modules\Event\Domain\Enums\GameAdmissionDirectionEnum;
use App\Modules\Event\Domain\Enums\GameAdmissionStatusEnum;
use App\Modules\Event\Domain\Enums\GameRecruitmentModeEnum;
use App\Modules\Event\Domain\Enums\GameRosterStatusEnum;
use App\Modules\Event\Domain\Enums\GameStatusEnum;
use App\Modules\Event\Domain\Events\EventChanged;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Event\Domain\Models\Game;
use App\Modules\Event\Domain\Models\GameAdmission;
use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Notification\Application\DTO\CreateUserNotificationDTO;
use App\Modules\Notification\Application\UseCases\CreateUserNotificationHandler;
use App\Modules\Notification\Domain\Enums\UserNotificationTypeEnum;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class StandaloneGameQrJoinService
{
    public function __construct(
        private readonly EventManagementAccess $eventAccess,
        private readonly CreateUserNotificationHandler $notifications,
    ) {}

    public function isAvailable(Event $event, Game $game): bool
    {
        if ($event->type !== EventTypeEnum::GAME
            || (int) $event->primary_game_id !== (int) $game->id
            || $event->status !== EventStatusEnum::PUBLISHED
            || $event->visibility !== EventVisibilityEnum::PUBLIC
            || $game->recruitment_mode !== GameRecruitmentModeEnum::INDIVIDUAL_DRAFT
            || ! $game->accepts_applications
            || $game->actual_ended_at !== null) {
            return false;
        }

        if ($game->status === GameStatusEnum::IN_PROGRESS) {
            return true;
        }

        return $game->status === GameStatusEnum::SCHEDULED
            && ($event->ends_at === null || $event->ends_at->isFuture());
    }

    public function apply(Game $game, Actor $actor): GameAdmission
    {
        $admission = DB::transaction(function () use ($game, $actor): GameAdmission {
            $event = Event::query()->whereKey($game->event_id)->lockForUpdate()->firstOrFail();
            $lockedGame = Game::query()->whereKey($game->id)->lockForUpdate()->firstOrFail();
            $this->assertAvailable($event, $lockedGame);

            $user = $actor->user?->canonical()
                ?? throw new InvalidArgumentException('Для заявки нужен аккаунт пользователя.');
            $lockedUser = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();

            $duplicate = $lockedGame->admissions()
                ->where('candidate_type', GameAdmissionCandidateTypeEnum::USER->value)
                ->whereIn('user_id', $lockedUser->identityIds())
                ->whereIn('status', [
                    GameAdmissionStatusEnum::PENDING->value,
                    GameAdmissionStatusEnum::ACCEPTED->value,
                ])
                ->exists();
            if ($duplicate) {
                throw new InvalidArgumentException('У вас уже есть активная заявка или приглашение на эту игру.');
            }

            return $lockedGame->admissions()->create([
                'candidate_type' => GameAdmissionCandidateTypeEnum::USER,
                'user_id' => $lockedUser->id,
                'direction' => GameAdmissionDirectionEnum::APPLICATION,
                'status' => GameAdmissionStatusEnum::PENDING,
                'requested_by_actor_id' => $actor->id,
            ]);
        }, 3);

        $this->notifyOrganizer($game, $admission);
        event(new EventChanged($game->event_id));

        return $admission;
    }

    public function revoke(Game $game, GameAdmission $admission, Actor $actor): void
    {
        DB::transaction(function () use ($game, $admission, $actor): void {
            $lockedGame = Game::query()->whereKey($game->id)->lockForUpdate()->firstOrFail();
            $lockedAdmission = GameAdmission::query()->whereKey($admission->id)->lockForUpdate()->firstOrFail();
            $this->assertBelongsToGame($lockedAdmission, $lockedGame);

            $actorUser = $actor->user?->canonical();
            $admissionUser = $lockedAdmission->user?->canonical();
            if ($actorUser === null
                || $admissionUser === null
                || $actorUser->id !== $admissionUser->id
                || $lockedAdmission->direction !== GameAdmissionDirectionEnum::APPLICATION
                || $lockedAdmission->status !== GameAdmissionStatusEnum::PENDING) {
                throw new InvalidArgumentException('Эту заявку нельзя отозвать.');
            }

            $lockedAdmission->forceFill([
                'status' => GameAdmissionStatusEnum::REVOKED,
                'responded_by_actor_id' => $actor->id,
                'responded_at' => now(),
            ])->save();
        }, 3);

        event(new EventChanged($game->event_id));
    }

    public function acceptToSide(Game $game, GameAdmission $admission, Actor $actor, string $slot): GameAdmission
    {
        $updated = DB::transaction(function () use ($game, $admission, $actor, $slot): GameAdmission {
            $event = Event::query()->whereKey($game->event_id)->lockForUpdate()->firstOrFail();
            $this->eventAccess->assertAllows($event, $actor, EventResponsibilityPermissionEnum::MANAGE_PARTICIPANTS);
            $lockedGame = Game::query()->whereKey($game->id)->lockForUpdate()->firstOrFail();
            $this->assertLateAssignmentOpen($event, $lockedGame);

            $lockedAdmission = GameAdmission::query()
                ->whereKey($admission->id)
                ->with('user')
                ->lockForUpdate()
                ->firstOrFail();
            $this->assertBelongsToGame($lockedAdmission, $lockedGame);
            if ($lockedAdmission->candidate_type !== GameAdmissionCandidateTypeEnum::USER
                || $lockedAdmission->direction !== GameAdmissionDirectionEnum::APPLICATION
                || $lockedAdmission->status !== GameAdmissionStatusEnum::PENDING) {
                throw new InvalidArgumentException('Для добавления в сторону нужна ожидающая заявка игрока.');
            }

            $side = $lockedGame->sides()->where('slot', $slot)->lockForUpdate()->first();
            if ($side === null) {
                throw new InvalidArgumentException('Выбранная сторона игры недоступна.');
            }

            $user = $lockedAdmission->user?->canonical()
                ?? throw new InvalidArgumentException('У заявки отсутствует пользователь.');
            $user = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();

            if ($lockedGame->rosterEntries()->whereIn('user_id', $user->identityIds())->exists()) {
                throw new InvalidArgumentException('Игрок уже находится в составе этой игры.');
            }

            $participant = $event->participants()->whereIn('user_id', $user->identityIds())->lockForUpdate()->first();
            if ($participant === null) {
                $this->assertCapacity($event);
                $participant = $event->participants()->create([
                    'user_id' => $user->id,
                    'role' => EventParticipantRoleEnum::PARTICIPANT,
                    'status' => EventParticipantStatusEnum::CONFIRMED,
                    'joined_at' => now(),
                    'confirmation_version' => $event->participation_confirmation_version,
                ]);
            } else {
                if ($participant->status !== EventParticipantStatusEnum::CONFIRMED) {
                    $this->assertCapacity($event);
                }
                $participant->forceFill([
                    'user_id' => $user->id,
                    'status' => EventParticipantStatusEnum::CONFIRMED,
                    'left_at' => null,
                    'confirmation_version' => $event->participation_confirmation_version,
                ])->save();
            }

            $lockedGame->rosterEntries()->create([
                'game_side_id' => $side->id,
                'user_id' => $user->id,
                'source_event_participant_id' => $participant->id,
                'status' => GameRosterStatusEnum::SELECTED,
            ]);

            $lockedAdmission->forceFill([
                'status' => GameAdmissionStatusEnum::ACCEPTED,
                'responded_by_actor_id' => $actor->id,
                'responded_at' => now(),
                'response_comment' => null,
            ])->save();

            return $lockedAdmission->refresh();
        }, 3);

        $this->notifyApplicant($game, $updated, true, $slot);
        event(new EventChanged($game->event_id));

        return $updated;
    }

    public function decline(Game $game, GameAdmission $admission, Actor $actor, ?string $comment = null): GameAdmission
    {
        $updated = DB::transaction(function () use ($game, $admission, $actor, $comment): GameAdmission {
            $event = Event::query()->whereKey($game->event_id)->lockForUpdate()->firstOrFail();
            $this->eventAccess->assertAllows($event, $actor, EventResponsibilityPermissionEnum::MANAGE_PARTICIPANTS);
            $lockedGame = Game::query()->whereKey($game->id)->lockForUpdate()->firstOrFail();
            $this->assertDecisionOpen($event, $lockedGame);

            $lockedAdmission = GameAdmission::query()->whereKey($admission->id)->lockForUpdate()->firstOrFail();
            $this->assertBelongsToGame($lockedAdmission, $lockedGame);
            if ($lockedAdmission->direction !== GameAdmissionDirectionEnum::APPLICATION
                || $lockedAdmission->status !== GameAdmissionStatusEnum::PENDING) {
                throw new InvalidArgumentException('Эта заявка уже обработана.');
            }

            $lockedAdmission->forceFill([
                'status' => GameAdmissionStatusEnum::DECLINED,
                'responded_by_actor_id' => $actor->id,
                'responded_at' => now(),
                'response_comment' => $this->normalizeComment($comment),
            ])->save();

            return $lockedAdmission->refresh();
        }, 3);

        $this->notifyApplicant($game, $updated, false);
        event(new EventChanged($game->event_id));

        return $updated;
    }

    public function setApplicationsEnabled(Game $game, Actor $actor, bool $enabled): Game
    {
        $updated = DB::transaction(function () use ($game, $actor, $enabled): Game {
            $event = Event::query()->whereKey($game->event_id)->lockForUpdate()->firstOrFail();
            $this->eventAccess->assertAllows($event, $actor, EventResponsibilityPermissionEnum::MANAGE_PARTICIPANTS);
            $lockedGame = Game::query()->whereKey($game->id)->lockForUpdate()->firstOrFail();
            $this->assertDecisionOpen($event, $lockedGame);
            if ($lockedGame->recruitment_mode !== GameRecruitmentModeEnum::INDIVIDUAL_DRAFT) {
                throw new InvalidArgumentException('Поздний набор доступен только для balanced-игры из отдельных игроков.');
            }

            $lockedGame->forceFill(['accepts_applications' => $enabled])->save();

            return $lockedGame->fresh();
        }, 3);

        event(new EventChanged($updated->event_id));

        return $updated;
    }

    private function assertAvailable(Event $event, Game $game): void
    {
        if (! $this->isAvailable($event, $game)) {
            throw new InvalidArgumentException('Сейчас новые заявки на эту игру не принимаются.');
        }
    }

    private function assertDecisionOpen(Event $event, Game $game): void
    {
        if ($event->type !== EventTypeEnum::GAME
            || (int) $event->primary_game_id !== (int) $game->id
            || $game->recruitment_mode !== GameRecruitmentModeEnum::INDIVIDUAL_DRAFT
            || $game->actual_ended_at !== null
            || ! in_array($game->status, [GameStatusEnum::SCHEDULED, GameStatusEnum::IN_PROGRESS], true)) {
            throw new InvalidArgumentException('Набор участников для этой игры уже закрыт.');
        }
    }

    private function assertLateAssignmentOpen(Event $event, Game $game): void
    {
        $this->assertDecisionOpen($event, $game);
        if ($game->sides_confirmed_at === null || $game->sides()->count() !== 2) {
            throw new InvalidArgumentException('Сначала утвердите две стороны игры.');
        }
    }

    private function assertCapacity(Event $event): void
    {
        if ($event->max_participants === null) {
            return;
        }

        $confirmed = $event->participants()
            ->where('status', EventParticipantStatusEnum::CONFIRMED->value)
            ->count();
        if ($confirmed >= $event->max_participants) {
            throw new InvalidArgumentException('Достигнут максимальный лимит участников мероприятия.');
        }
    }

    private function assertBelongsToGame(GameAdmission $admission, Game $game): void
    {
        if ((int) $admission->game_id !== (int) $game->id) {
            throw new InvalidArgumentException('Заявка не относится к этой игре.');
        }
    }

    private function notifyOrganizer(Game $game, GameAdmission $admission): void
    {
        $event = $game->event()->first();
        $ownerUserId = $event?->organizerActor()->value('user_id');
        if ($event === null || $ownerUserId === null) {
            return;
        }

        $this->notifications->handle(new CreateUserNotificationDTO(
            userId: (int) $ownerUserId,
            type: UserNotificationTypeEnum::SYSTEM,
            title: 'Новая заявка на игру',
            body: $game->status === GameStatusEnum::IN_PROGRESS
                ? 'Игрок хочет присоединиться к уже идущей игре.'
                : 'Игрок подал заявку на участие в игре.',
            actionUrl: route('events.games.manage', [$event->routeIdentifier(), $game->id], false),
            actionText: 'Рассмотреть заявку',
            payload: [
                'source' => 'game.recruitment',
                'game_id' => $game->id,
                'event_id' => $event->id,
                'game_admission_id' => $admission->id,
            ],
        ));
    }

    private function notifyApplicant(Game $game, GameAdmission $admission, bool $accepted, ?string $slot = null): void
    {
        if ($admission->user_id === null) {
            return;
        }
        $event = $game->event()->first();
        if ($event === null) {
            return;
        }

        $this->notifications->handle(new CreateUserNotificationDTO(
            userId: (int) $admission->user->canonical()->id,
            type: UserNotificationTypeEnum::SYSTEM,
            title: $accepted ? 'Заявка на игру принята' : 'Заявка на игру отклонена',
            body: $accepted
                ? 'Вы добавлены в сторону '.($slot ?? '').' игры «'.$event->title.'».'
                : 'Организатор отклонил вашу заявку на игру «'.$event->title.'».'
                    .($admission->response_comment ? ' Причина: '.$admission->response_comment : ''),
            actionUrl: route('events.games.recruitment.join', [$event->routeIdentifier(), $game->id], false),
            actionText: 'Открыть игру',
            payload: [
                'source' => 'game.recruitment',
                'game_id' => $game->id,
                'event_id' => $event->id,
                'game_admission_id' => $admission->id,
                'game_admission_status' => $accepted
                    ? GameAdmissionStatusEnum::ACCEPTED->value
                    : GameAdmissionStatusEnum::DECLINED->value,
            ],
        ));
    }

    private function normalizeComment(?string $comment): ?string
    {
        $comment = trim((string) $comment);

        return $comment === '' ? null : mb_substr($comment, 0, 2000);
    }
}
