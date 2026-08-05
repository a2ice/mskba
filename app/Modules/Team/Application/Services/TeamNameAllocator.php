<?php

namespace App\Modules\Team\Application\Services;

use App\Modules\Team\Domain\Enums\TeamStatusEnum;
use App\Modules\Team\Domain\Models\Team;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class TeamNameAllocator
{
    /** @return array{base_name: string, normalized_name: string, name_sequence: int, name: string, has_duplicate: bool} */
    public function allocate(string $requestedName, int $creatorUserId, ?Team $except = null): array
    {
        $baseName = $this->clean($requestedName);
        $normalized = $this->normalize($baseName);
        $this->lockName($normalized);

        $matches = $this->activeMatches($normalized, $except)->get();
        if ($matches->contains(fn (Team $team): bool => (int) $team->createdByActor?->user_id === $creatorUserId)) {
            throw new InvalidArgumentException('У вас уже есть активная команда с таким названием. Измените существующую команду или выберите другое название.');
        }

        $sequence = max(1, ((int) $matches->max('name_sequence')) + 1);

        return [
            'base_name' => $baseName,
            'normalized_name' => $normalized,
            'name_sequence' => $sequence,
            'name' => $sequence === 1 ? $baseName : "{$baseName} №{$sequence}",
            'has_duplicate' => $matches->isNotEmpty(),
        ];
    }

    /** @return array{has_duplicate: bool, suggested_name: string, owned_by_current_user: bool} */
    public function suggest(string $requestedName, ?Team $except = null, ?int $currentUserId = null): array
    {
        $baseName = $this->clean($requestedName);
        if ($baseName === '') {
            return ['has_duplicate' => false, 'suggested_name' => '', 'owned_by_current_user' => false];
        }

        $matches = $this->activeMatches($this->normalize($baseName), $except)->get();
        $sequence = max(1, ((int) $matches->max('name_sequence')) + 1);

        return [
            'has_duplicate' => $matches->isNotEmpty(),
            'suggested_name' => $sequence === 1 ? $baseName : "{$baseName} №{$sequence}",
            'owned_by_current_user' => $currentUserId !== null
                && $matches->contains(fn (Team $team): bool => (int) $team->createdByActor?->user_id === $currentUserId),
        ];
    }

    public function clean(string $name): string
    {
        return trim(preg_replace('/\s+/u', ' ', $name) ?? $name);
    }

    public function normalize(string $name): string
    {
        return str_replace('ё', 'е', Str::lower($this->clean($name)));
    }

    /** @return Builder<Team> */
    private function activeMatches(string $normalized, ?Team $except): Builder
    {
        return Team::query()
            ->with('createdByActor:id,user_id')
            ->whereNull('temporary_for_event_id')
            ->where('status', TeamStatusEnum::ACTIVE->value)
            ->where('normalized_name', $normalized)
            ->when($except !== null, fn ($query) => $query->where('id', '!=', $except->id));
    }

    private function lockName(string $normalized): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::select('select pg_advisory_xact_lock(hashtext(?))', [$normalized]);
        }
    }
}
