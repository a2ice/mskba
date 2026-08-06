<?php

namespace App\Modules\Event\Application\Services;

use App\Modules\Event\Domain\Enums\EventStatusEnum;
use App\Modules\Event\Domain\Enums\GameStatisticsStatusEnum;
use App\Modules\Event\Domain\Enums\GameStatusEnum;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Event\Domain\Models\Game;
use App\Modules\Event\Domain\Models\LegacyGameRoute;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class LegacyGamesMigrationService
{
    public function ensureMigrated(Event $legacyEvent): Game
    {
        $existing = Game::query()->where('legacy_event_id', $legacyEvent->id)->first();
        if ($existing !== null) {
            return $existing;
        }

        $conflicts = $this->conflictsFor($legacyEvent->loadMissing(['gameDetail', 'parentEvent']));
        if ($conflicts->isNotEmpty()) {
            throw new \InvalidArgumentException($conflicts->implode('; '));
        }

        return $this->migrate($legacyEvent->id);
    }

    /**
     * @return array{candidates: int, migrated: int, existing: int, conflicts: list<string>}
     */
    public function run(bool $apply): array
    {
        $result = ['candidates' => 0, 'migrated' => 0, 'existing' => 0, 'conflicts' => []];

        $candidates = Event::query()
            ->whereHas('gameDetail')
            ->with(['gameDetail', 'parentEvent'])
            ->orderBy('id')
            ->get();

        $pending = collect();
        $candidates->each(function (Event $legacyEvent) use (&$pending, &$result): void {
            $result['candidates']++;

            if (Game::query()->where('legacy_event_id', $legacyEvent->id)->exists()) {
                $result['existing']++;

                return;
            }

            $conflicts = $this->conflictsFor($legacyEvent);
            if ($conflicts->isNotEmpty()) {
                foreach ($conflicts as $conflict) {
                    $result['conflicts'][] = "Event #{$legacyEvent->id}: {$conflict}";
                }

                return;
            }

            $pending->push($legacyEvent->id);
        });

        if ($apply && $result['conflicts'] === []) {
            $pending->each(function (int $legacyEventId) use (&$result): void {
                $this->migrate($legacyEventId);
                $result['migrated']++;
            });
        }

        return $result;
    }

    /** @return Collection<int, string> */
    private function conflictsFor(Event $legacyEvent): Collection
    {
        if ($legacyEvent->parent_event_id === null) {
            return collect();
        }

        $parent = $legacyEvent->parentEvent;
        if ($parent === null) {
            return collect(['родительское мероприятие не найдено']);
        }

        $conflicts = collect();
        if ($legacyEvent->venue_id !== $parent->venue_id) {
            $conflicts->push('площадка отличается от площадки родительского мероприятия');
        }
        if ($legacyEvent->booking()->exists()) {
            $conflicts->push('есть отдельное бронирование');
        }
        if ($legacyEvent->telegramPublications()->exists()) {
            $conflicts->push('есть собственная Telegram-публикация');
        }
        if ($legacyEvent->media()->exists()) {
            $conflicts->push('есть собственные медиа, требуется явный перенос');
        }
        if ($legacyEvent->gameDetail?->is_time_scheduled
            && ($legacyEvent->starts_at->lessThan($parent->starts_at)
                || $legacyEvent->ends_at->greaterThan($parent->ends_at))) {
            $conflicts->push('игровой слот выходит за интервал родительского мероприятия');
        }

        return $conflicts;
    }

    private function migrate(int $legacyEventId): Game
    {
        return DB::transaction(function () use ($legacyEventId): Game {
            $legacyEvent = Event::query()->lockForUpdate()->findOrFail($legacyEventId);
            $parent = $legacyEvent->parent_event_id === null
                ? $legacyEvent
                : Event::query()->lockForUpdate()->findOrFail($legacyEvent->parent_event_id);

            if ($legacyEvent->parent_event_id !== null) {
                $this->mergeParticipantsIntoParent($legacyEvent, $parent);
            }

            $existing = Game::query()->where('legacy_event_id', $legacyEvent->id)->lockForUpdate()->first();
            if ($existing !== null) {
                return $existing;
            }

            $detail = $legacyEvent->gameDetail()->lockForUpdate()->firstOrFail();
            $game = Game::query()->create([
                'event_id' => $parent->id,
                'legacy_event_id' => $legacyEvent->id,
                'created_by_actor_id' => $legacyEvent->organizer_actor_id,
                'title' => $legacyEvent->parent_event_id === null ? null : $legacyEvent->title,
                'description' => $legacyEvent->parent_event_id === null ? null : $legacyEvent->description,
                'status' => $this->gameStatus($legacyEvent, $detail->statistics_status),
                'side_a_size' => $detail->side_a_size,
                'side_b_size' => $detail->side_b_size,
                'scoring_type' => $detail->scoring_type,
                'statistics_mode' => $detail->statistics_mode,
                'statistics_status' => $detail->statistics_status,
                'statistics_version' => $detail->statistics_version,
                'statistics_confirmed_at' => $detail->statistics_confirmed_at,
                'statistics_confirmed_by_actor_id' => $detail->statistics_confirmed_by_actor_id,
                'scheduled_starts_at' => $detail->is_time_scheduled ? $legacyEvent->starts_at : null,
                'scheduled_ends_at' => $detail->is_time_scheduled ? $legacyEvent->ends_at : null,
                'actual_started_at' => $legacyEvent->actual_started_at,
                'actual_started_by_actor_id' => $legacyEvent->actual_started_by_actor_id,
                'actual_ended_at' => $legacyEvent->actual_ended_at,
                'actual_ended_by_actor_id' => $legacyEvent->actual_ended_by_actor_id,
                'completed_at' => $legacyEvent->completed_at,
                'completed_by_actor_id' => $legacyEvent->completed_by_actor_id,
                'cancelled_at' => $legacyEvent->cancelled_at,
                'cancelled_by_actor_id' => $legacyEvent->cancelled_by_actor_id,
                'cancellation_reason' => $legacyEvent->cancellation_reason,
            ]);

            DB::table('game_sides')->where('event_id', $legacyEvent->id)->update(['game_id' => $game->id]);
            DB::table('game_roster_entries')->where('event_id', $legacyEvent->id)->update(['game_id' => $game->id]);
            DB::table('game_player_statistics')->where('event_id', $legacyEvent->id)->update(['game_id' => $game->id]);

            if ($legacyEvent->parent_event_id !== null) {
                LegacyGameRoute::query()->updateOrCreate(
                    ['legacy_event_id' => $legacyEvent->id],
                    [
                        'legacy_identifier' => $legacyEvent->routeIdentifier(),
                        'game_id' => $game->id,
                    ],
                );
                DB::table('teams')
                    ->where('temporary_for_event_id', $legacyEvent->id)
                    ->update(['temporary_for_event_id' => $parent->id]);
            }

            return $game;
        }, 3);
    }

    private function mergeParticipantsIntoParent(Event $legacyEvent, Event $parent): void
    {
        $statusPriority = ['left' => 1, 'tentative' => 2, 'confirmed' => 3];
        $responsibilityPriority = ['declined' => 1, 'pending' => 2, 'accepted' => 3];

        $children = DB::table('event_participants')
            ->where('event_id', $legacyEvent->id)
            ->lockForUpdate()
            ->orderBy('id')
            ->get();

        foreach ($children as $child) {
            $existing = DB::table('event_participants')
                ->where('event_id', $parent->id)
                ->where('user_id', $child->user_id)
                ->lockForUpdate()
                ->first();

            if ($existing === null) {
                DB::table('event_participants')->where('id', $child->id)->update([
                    'event_id' => $parent->id,
                    'updated_at' => now(),
                ]);

                continue;
            }

            $incomingStatusWins = ($statusPriority[$child->status] ?? 0)
                > ($statusPriority[$existing->status] ?? 0);
            $incomingResponsibilityWins = ($responsibilityPriority[$child->responsibility_status] ?? 0)
                > ($responsibilityPriority[$existing->responsibility_status] ?? 0);

            $updates = [
                'role' => $existing->role === 'organizer' || $child->role !== 'organizer'
                    ? $existing->role
                    : $child->role,
                'joined_at' => collect([$existing->joined_at, $child->joined_at])->filter()->min(),
                'confirmation_version' => max(
                    (int) ($existing->confirmation_version ?? 0),
                    (int) ($child->confirmation_version ?? 0),
                ),
                'updated_at' => now(),
            ];

            if ($incomingStatusWins) {
                $updates = [...$updates,
                    'status' => $child->status,
                    'left_at' => $child->left_at,
                    'status_changed_by_actor_id' => $child->status_changed_by_actor_id,
                    'status_changed_at' => $child->status_changed_at,
                ];
            }
            if (($updates['status'] ?? $existing->status) !== 'left') {
                $updates['left_at'] = null;
            }
            if ($incomingResponsibilityWins) {
                $updates = [...$updates,
                    'responsibility_status' => $child->responsibility_status,
                    'responsibility_requested_by_user_id' => $child->responsibility_requested_by_user_id,
                    'responsibility_requested_at' => $child->responsibility_requested_at,
                    'responsibility_responded_at' => $child->responsibility_responded_at,
                ];
            }

            DB::table('event_participants')->where('id', $existing->id)->update($updates);
            DB::table('event_responsibility_permissions')
                ->where('event_participant_id', $child->id)
                ->pluck('permission')
                ->each(fn (string $permission) => DB::table('event_responsibility_permissions')->updateOrInsert(
                    ['event_participant_id' => $existing->id, 'permission' => $permission],
                    ['created_at' => now(), 'updated_at' => now()],
                ));
            DB::table('event_participants')->where('id', $child->id)->delete();
        }
    }

    private function gameStatus(Event $event, GameStatisticsStatusEnum $statisticsStatus): GameStatusEnum
    {
        if ($event->status === EventStatusEnum::CANCELLED) {
            return GameStatusEnum::CANCELLED;
        }
        if ($event->status === EventStatusEnum::COMPLETED
            || $statisticsStatus === GameStatisticsStatusEnum::CONFIRMED) {
            return GameStatusEnum::COMPLETED;
        }
        if ($event->actual_ended_at !== null
            || $statisticsStatus === GameStatisticsStatusEnum::READY) {
            return GameStatusEnum::AWAITING_RESULT;
        }
        if ($event->actual_started_at !== null
            || $statisticsStatus === GameStatisticsStatusEnum::ENTERING) {
            return GameStatusEnum::IN_PROGRESS;
        }

        return GameStatusEnum::SCHEDULED;
    }
}
