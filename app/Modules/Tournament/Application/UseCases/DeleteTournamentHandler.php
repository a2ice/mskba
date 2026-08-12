<?php

namespace App\Modules\Tournament\Application\UseCases;

use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\Tournament\Application\Services\TournamentAccess;
use App\Modules\Tournament\Domain\Enums\TournamentPermissionEnum;
use App\Modules\Tournament\Domain\Models\Tournament;
use Illuminate\Support\Facades\DB;

final class DeleteTournamentHandler
{
    public function __construct(private readonly TournamentAccess $access) {}

    public function handle(string $identifier, Actor $actor, string $reason): void
    {
        DB::transaction(function () use ($identifier, $actor, $reason): void {
            $tournament = Tournament::query()->whereRouteIdentifier($identifier)->lockForUpdate()->firstOrFail();
            $this->access->assertAllows($tournament, $actor, TournamentPermissionEnum::DELETE);
            $tournament->forceFill(['status_comment' => trim($reason)])->save();
            $tournament->delete();
        });
    }
}
