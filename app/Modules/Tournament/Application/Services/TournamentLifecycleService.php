<?php

namespace App\Modules\Tournament\Application\Services;

use App\Modules\Event\Domain\Enums\GameStatusEnum;
use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\Tournament\Domain\Enums\TournamentPermissionEnum;
use App\Modules\Tournament\Domain\Enums\TournamentStatusEnum;
use App\Modules\Tournament\Domain\Models\Tournament;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class TournamentLifecycleService
{
    public function __construct(private readonly TournamentAccess $access) {}

    public function close(Tournament $tournament, Actor $actor): void
    {
        DB::transaction(function () use ($tournament, $actor): void {
            $locked = Tournament::query()->whereKey($tournament->id)->lockForUpdate()->firstOrFail();
            $this->access->assertAllows($locked, $actor, TournamentPermissionEnum::MANAGE_STATUS);

            if ($locked->status === TournamentStatusEnum::CANCELLED) {
                throw new InvalidArgumentException('Отменённый турнир нельзя завершить.');
            }
            if ($locked->tournament_closed_at !== null) {
                throw new InvalidArgumentException('Турнир уже завершён.');
            }
            if ($locked->matches()->whereHas('game', fn ($query) => $query
                ->whereIn('status', [
                    GameStatusEnum::SCHEDULED->value,
                    GameStatusEnum::IN_PROGRESS->value,
                    GameStatusEnum::AWAITING_RESULT->value,
                ]))->exists()) {
                throw new InvalidArgumentException('Сначала завершите или отмените все назначенные игры турнира.');
            }

            $attributes = [
                'tournament_closed_at' => now(),
                'tournament_closed_by_actor_id' => $actor->id,
            ];
            if ($locked->recruitment_closed_at === null) {
                $attributes['recruitment_closed_at'] = now();
                $attributes['recruitment_closed_by_actor_id'] = $actor->id;
            }

            $locked->forceFill($attributes)->save();
        });
    }
}
