<?php

namespace App\Modules\Event\Application\Services;

use App\Modules\Event\Domain\Enums\EventParticipantRoleEnum;
use App\Modules\Event\Domain\Enums\EventParticipantStatusEnum;
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
use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class StandaloneGamePlayerWithdrawalService
{
    public function withdraw(Game $game, Actor $actor): bool
    {
        $changed = DB::transaction(function () use ($game, $actor): bool {
            $event = Event::query()->whereKey($game->event_id)->lockForUpdate()->firstOrFail();
            $lockedGame = Game::query()->whereKey($game->id)->lockForUpdate()->firstOrFail();
            $this->assertWithdrawalOpen($event, $lockedGame);

            $user = $actor->user?->canonical()
                ?? throw new InvalidArgumentException('Для изменения участия нужен аккаунт пользователя.');
            $user = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            if ($user->isBlocked() || $user->trashed()) {
                throw new InvalidArgumentException('Этот пользователь не может изменять участие.');
            }
            $identityIds = $user->identityIds();

            $admissions = $lockedGame->admissions()
                ->where('candidate_type', GameAdmissionCandidateTypeEnum::USER->value)
                ->whereIn('user_id', $identityIds)
                ->whereIn('status', [
                    GameAdmissionStatusEnum::PENDING->value,
                    GameAdmissionStatusEnum::ACCEPTED->value,
                ])
                ->lockForUpdate()
                ->get();

            $changed = false;
            foreach ($admissions as $admission) {
                $status = $admission->status === GameAdmissionStatusEnum::PENDING
                    && $admission->direction === GameAdmissionDirectionEnum::INVITATION
                        ? GameAdmissionStatusEnum::DECLINED
                        : GameAdmissionStatusEnum::REVOKED;
                $admission->forceFill([
                    'status' => $status,
                    'responded_by_actor_id' => $actor->id,
                    'responded_at' => now(),
                ])->save();
                $changed = true;
            }

            $rosterEntries = $lockedGame->rosterEntries()
                ->whereIn('user_id', $identityIds)
                ->where('status', '!=', GameRosterStatusEnum::EXCLUDED->value)
                ->lockForUpdate()
                ->get();
            foreach ($rosterEntries as $entry) {
                // Roster is a historical snapshot. Keep the player's former lineup role,
                // captain flag and lock timestamp; only mark them no longer active.
                $entry->forceFill([
                    'status' => GameRosterStatusEnum::EXCLUDED,
                ])->save();
                $changed = true;
            }

            $participants = $event->participants()
                ->whereIn('user_id', $identityIds)
                ->lockForUpdate()
                ->get();
            foreach ($participants as $participant) {
                if ($participant->role === EventParticipantRoleEnum::ORGANIZER) {
                    continue;
                }
                if ($participant->status !== EventParticipantStatusEnum::LEFT) {
                    $participant->responsibilityPermissions()->delete();
                    $participant->forceFill([
                        'user_id' => $user->id,
                        'status' => EventParticipantStatusEnum::LEFT,
                        // Preserve joined_at as participation history; left_at marks withdrawal.
                        'left_at' => now(),
                        'confirmation_version' => $event->participation_confirmation_version,
                        'responsibility_status' => null,
                        'responsibility_requested_by_user_id' => null,
                        'responsibility_requested_at' => null,
                        'responsibility_responded_at' => null,
                        'status_changed_by_actor_id' => null,
                        'status_changed_at' => null,
                    ])->save();
                    $changed = true;
                }
            }

            return $changed;
        }, 3);

        if ($changed) {
            event(new EventChanged($game->event_id));
        }

        return $changed;
    }

    private function assertWithdrawalOpen(Event $event, Game $game): void
    {
        if ($event->type !== EventTypeEnum::GAME
            || (int) $event->primary_game_id !== (int) $game->id
            || $game->recruitment_mode !== GameRecruitmentModeEnum::INDIVIDUAL_DRAFT) {
            throw new InvalidArgumentException('Индивидуальное участие для этой игры не используется.');
        }
        if ($event->status !== EventStatusEnum::PUBLISHED
            || $event->visibility !== EventVisibilityEnum::PUBLIC) {
            throw new InvalidArgumentException('Для этой игры сейчас нельзя изменить участие.');
        }
        if ($game->actual_ended_at !== null
            || ! in_array($game->status, [GameStatusEnum::SCHEDULED, GameStatusEnum::IN_PROGRESS], true)
            || ($game->status === GameStatusEnum::SCHEDULED && $event->ends_at->lessThanOrEqualTo(now()))) {
            throw new InvalidArgumentException('Игра уже завершена.');
        }
    }
}
