<?php

namespace App\Modules\Tournament\Application\Services;

use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\Tournament\Domain\Enums\TournamentEntryStatusEnum;
use App\Modules\Tournament\Domain\Enums\TournamentPermissionEnum;
use App\Modules\Tournament\Domain\Enums\TournamentRecruitmentModeEnum;
use App\Modules\Tournament\Domain\Models\Tournament;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class TournamentParticipantPoolService
{
    public function __construct(private readonly TournamentAccess $access) {}

    public function lock(Tournament $tournament, Actor $actor): void
    {
        DB::transaction(function () use ($tournament, $actor): void {
            $locked = Tournament::query()->whereKey($tournament->id)->lockForUpdate()->firstOrFail();
            $this->access->assertAllows($locked, $actor, TournamentPermissionEnum::MANAGE_GAMES);
            if ($locked->recruitment_mode !== TournamentRecruitmentModeEnum::PREFORMED_TEAMS) {
                throw new InvalidArgumentException('В balanced-режиме пул фиксируется при утверждении команд.');
            }

            if ($locked->isContinuous()) {
                if ($locked->tournament_closed_at !== null) {
                    throw new InvalidArgumentException('Завершённый турнир нельзя изменять.');
                }
                if ($locked->recruitment_closed_at !== null) {
                    throw new InvalidArgumentException('Набор команд уже закрыт.');
                }
                $locked->forceFill([
                    'recruitment_closed_at' => now(),
                    'recruitment_closed_by_actor_id' => $actor->id,
                ])->save();

                return;
            }

            if ($locked->entries()->where('status', TournamentEntryStatusEnum::ACTIVE->value)->count() < 2) {
                throw new InvalidArgumentException('Для завершения набора нужно не меньше двух принятых команд.');
            }
            $locked->forceFill(['participant_pool_locked_at' => now()])->save();
        });
    }

    public function unlock(Tournament $tournament, Actor $actor): void
    {
        DB::transaction(function () use ($tournament, $actor): void {
            $locked = Tournament::query()->whereKey($tournament->id)->lockForUpdate()->firstOrFail();
            $this->access->assertAllows($locked, $actor, TournamentPermissionEnum::MANAGE_GAMES);
            if ($locked->recruitment_mode !== TournamentRecruitmentModeEnum::PREFORMED_TEAMS) {
                throw new InvalidArgumentException('В balanced-режиме используйте расформирование команд.');
            }

            if ($locked->isContinuous()) {
                if ($locked->tournament_closed_at !== null) {
                    throw new InvalidArgumentException('После завершения турнира возобновить набор нельзя.');
                }
                $locked->forceFill([
                    'recruitment_closed_at' => null,
                    'recruitment_closed_by_actor_id' => null,
                ])->save();

                return;
            }

            if ($locked->matches()->whereNotNull('game_id')->exists()) {
                throw new InvalidArgumentException('После назначения хотя бы одного матча возобновить набор нельзя.');
            }
            $locked->matches()->lockForUpdate()->get()->each->forceDelete();
            $locked->forceFill(['participant_pool_locked_at' => null])->save();
        });
    }
}
