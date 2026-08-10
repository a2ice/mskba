<?php

namespace App\Modules\Tournament\Application\UseCases;

use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\Tournament\Application\Services\TournamentAccess;
use App\Modules\Tournament\Domain\Enums\TournamentPermissionEnum;
use App\Modules\Tournament\Domain\Enums\TournamentStatusEnum;
use App\Modules\Tournament\Domain\Models\Tournament;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class ChangeTournamentStatusHandler
{
    public function __construct(private readonly TournamentAccess $access) {}

    public function handle(
        string $identifier,
        Actor $actor,
        TournamentStatusEnum $status,
        ?string $comment,
    ): Tournament {
        return DB::transaction(function () use ($identifier, $actor, $status, $comment): Tournament {
            $tournament = Tournament::query()->whereRouteIdentifier($identifier)->lockForUpdate()->firstOrFail();
            $this->access->assertAllows($tournament, $actor, TournamentPermissionEnum::MANAGE_STATUS);
            if ($tournament->status === TournamentStatusEnum::CANCELLED
                && $status !== TournamentStatusEnum::CANCELLED) {
                throw new InvalidArgumentException('Отменённый турнир нельзя вернуть в активный статус.');
            }

            $tournament->forceFill([
                'status' => $status,
                'status_comment' => $comment ?: null,
            ])->save();

            return $tournament->refresh();
        });
    }
}
