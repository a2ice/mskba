<?php

namespace App\Modules\Tournament\Application\UseCases;

use App\Modules\Event\Domain\Enums\GameFormatEnum;
use App\Modules\Event\Domain\Enums\GameStatusEnum;
use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\Tournament\Application\Services\TournamentAccess;
use App\Modules\Tournament\Domain\Enums\TournamentEnrollmentPolicyEnum;
use App\Modules\Tournament\Domain\Enums\TournamentPermissionEnum;
use App\Modules\Tournament\Domain\Enums\TournamentRecruitmentModeEnum;
use App\Modules\Tournament\Domain\Enums\TournamentStatusEnum;
use App\Modules\Tournament\Domain\Models\Tournament;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class UpdateTournamentHandler
{
    public function __construct(
        private readonly TournamentAccess $access,
    ) {}

    /** @param array<string, mixed> $data */
    public function handle(string $identifier, Actor $actor, array $data): Tournament
    {
        return DB::transaction(function () use ($identifier, $actor, $data): Tournament {
            $tournament = Tournament::query()->whereRouteIdentifier($identifier)->lockForUpdate()->firstOrFail();
            $isOwner = $this->access->isOwner($tournament, $actor);
            if (! $isOwner) {
                $this->access->assertAllows($tournament, $actor, TournamentPermissionEnum::MANAGE_DESCRIPTION);
            }
            if ($tournament->status === TournamentStatusEnum::CANCELLED) {
                throw new InvalidArgumentException('Отменённый турнир нельзя редактировать.');
            }

            $startsOn = CarbonImmutable::parse($data['starts_on'])->startOfDay();
            $endsOn = isset($data['ends_on']) ? CarbonImmutable::parse($data['ends_on'])->startOfDay() : null;
            if ($endsOn?->lessThan($startsOn)) {
                throw new InvalidArgumentException('Окончание турнира не может быть раньше начала.');
            }
            $scheduledMatches = $tournament->matches()->whereNotNull('game_id')->with('game.event')->get();
            $competitionStarted = $scheduledMatches->contains(fn ($match): bool => $match->game?->actual_started_at !== null
                || in_array($match->game?->status, [GameStatusEnum::IN_PROGRESS, GameStatusEnum::AWAITING_RESULT, GameStatusEnum::COMPLETED], true));
            $startsChanged = ! $startsOn->isSameDay($tournament->starts_on);
            $endsChanged = $endsOn?->toDateString() !== $tournament->ends_on?->toDateString();
            $datesChanged = $startsChanged || $endsChanged;
            if ($competitionStarted && $startsChanged) {
                throw new InvalidArgumentException('Дату начала турнира нельзя менять после начала первой игры.');
            }
            if ($competitionStarted && $endsChanged && ! $tournament->isContinuous()) {
                throw new InvalidArgumentException('Дату окончания обычного турнира нельзя менять после начала первой игры.');
            }
            if ($datesChanged && $scheduledMatches->contains(function ($match) use ($startsOn, $endsOn): bool {
                $event = $match->game?->event;

                return $event === null
                    || $event->starts_at->toDateString() < $startsOn->toDateString()
                    || ($endsOn !== null && $event->ends_at->toDateString() > $endsOn->toDateString());
            })) {
                throw new InvalidArgumentException('Новый диапазон дат должен включать все назначенные матчи.');
            }

            $attributes = [
                'short_description' => $data['short_description'] ?? null,
                'full_description' => $data['full_description'] ?? null,
            ];
            if ($isOwner) {
                $format = isset($data['format']) ? GameFormatEnum::from($data['format']) : null;
                $recruitmentMode = $format === GameFormatEnum::STREETBALL_1X1
                    ? TournamentRecruitmentModeEnum::INDIVIDUAL_DRAFT
                    : TournamentRecruitmentModeEnum::from($data['recruitment_mode'] ?? $tournament->recruitment_mode->value);
                $enrollmentPolicy = $recruitmentMode === TournamentRecruitmentModeEnum::PREFORMED_TEAMS
                    ? TournamentEnrollmentPolicyEnum::from($data['enrollment_policy'] ?? $tournament->enrollment_policy->value)
                    : TournamentEnrollmentPolicyEnum::FIXED_POOL;
                $roundRobinLegs = (int) ($data['round_robin_legs'] ?? $tournament->round_robin_legs ?? 1);
                $hasAdmissions = $tournament->admissions()->exists();
                $hasMatches = $tournament->matches()->exists();

                if ($recruitmentMode !== $tournament->recruitment_mode && $hasAdmissions) {
                    throw new InvalidArgumentException('Режим набора нельзя менять после первой заявки или приглашения.');
                }
                if ($recruitmentMode !== $tournament->recruitment_mode && $hasMatches) {
                    throw new InvalidArgumentException('Режим набора нельзя менять после появления матчей.');
                }
                if ($enrollmentPolicy !== $tournament->enrollment_policy && $hasAdmissions) {
                    throw new InvalidArgumentException('Тип набора нельзя менять после первой заявки или приглашения.');
                }
                if ($enrollmentPolicy !== $tournament->enrollment_policy && $hasMatches) {
                    throw new InvalidArgumentException('Тип набора нельзя менять после появления матчей.');
                }
                if ($roundRobinLegs !== (int) $tournament->round_robin_legs && $hasMatches) {
                    throw new InvalidArgumentException('Количество кругов нельзя менять после появления матчей.');
                }
                if (! in_array($roundRobinLegs, [1, 2], true)) {
                    throw new InvalidArgumentException('Поддерживается один или два круга.');
                }
                $participantPoolLocked = $tournament->participant_pool_locked_at !== null;
                $structuralSettingsLocked = $participantPoolLocked || $hasMatches;
                if ($format !== $tournament->format && $participantPoolLocked && ! $hasMatches) {
                    throw new InvalidArgumentException('Сначала разблокируйте пул участников, чтобы изменить формат турнира.');
                }
                if ($format !== $tournament->format && $hasMatches) {
                    throw new InvalidArgumentException('Формат турнира нельзя менять после появления матчей.');
                }
                if ($tournament->tournament_closed_at !== null && ($datesChanged
                    || $format !== $tournament->format
                    || $recruitmentMode !== $tournament->recruitment_mode
                    || $enrollmentPolicy !== $tournament->enrollment_policy
                    || $roundRobinLegs !== (int) $tournament->round_robin_legs)) {
                    throw new InvalidArgumentException('Спортивные настройки завершённого турнира менять нельзя.');
                }

                $attributes += [
                    'title' => $data['title'],
                    'default_venue_id' => $data['default_venue_id'] ?? null,
                    'starts_on' => $startsOn,
                    'ends_on' => $endsOn,
                    'format' => $format,
                    'recruitment_mode' => $recruitmentMode,
                    'enrollment_policy' => $enrollmentPolicy,
                    'round_robin_legs' => $roundRobinLegs,
                    'accepts_unconfirmed_participants' => $structuralSettingsLocked || ! $tournament->acceptsAdmissions()
                        ? $tournament->accepts_unconfirmed_participants
                        : $recruitmentMode === TournamentRecruitmentModeEnum::INDIVIDUAL_DRAFT
                            && (bool) ($data['accepts_unconfirmed_participants'] ?? false),
                ];
            }
            $tournament->forceFill($attributes)->save();

            return $tournament->refresh();
        });
    }
}
