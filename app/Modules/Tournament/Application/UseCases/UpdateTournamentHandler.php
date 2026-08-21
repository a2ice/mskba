<?php

namespace App\Modules\Tournament\Application\UseCases;

use App\Modules\Event\Domain\Enums\GameFormatEnum;
use App\Modules\Event\Domain\Enums\GameStatusEnum;
use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\Tournament\Application\Services\TournamentAccess;
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
                || in_array($match->game?->status, [GameStatusEnum::IN_PROGRESS, GameStatusEnum::COMPLETED], true));
            $datesChanged = ! $startsOn->isSameDay($tournament->starts_on)
                || ($endsOn?->toDateString() !== $tournament->ends_on?->toDateString());
            if ($competitionStarted && $datesChanged) {
                throw new InvalidArgumentException('Даты турнира нельзя менять после начала первой игры.');
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
                if ($recruitmentMode !== $tournament->recruitment_mode && $tournament->admissions()->exists()) {
                    throw new InvalidArgumentException('Режим набора нельзя менять после первой заявки или приглашения.');
                }
                $participantPoolLocked = $tournament->participant_pool_locked_at !== null;
                if ($format !== $tournament->format && $participantPoolLocked) {
                    throw new InvalidArgumentException('Сначала разблокируйте пул участников, чтобы изменить формат турнира.');
                }
                $attributes += [
                    'title' => $data['title'],
                    'default_venue_id' => $data['default_venue_id'] ?? null,
                    'starts_on' => $startsOn,
                    'ends_on' => $endsOn,
                    'format' => $format,
                    'recruitment_mode' => $recruitmentMode,
                    'accepts_unconfirmed_participants' => $participantPoolLocked || ! $tournament->acceptsAdmissions()
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
