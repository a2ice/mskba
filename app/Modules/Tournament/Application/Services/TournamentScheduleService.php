<?php

namespace App\Modules\Tournament\Application\Services;

use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\Tournament\Domain\Enums\TournamentEntryStatusEnum;
use App\Modules\Tournament\Domain\Enums\TournamentPermissionEnum;
use App\Modules\Tournament\Domain\Enums\TournamentStatusEnum;
use App\Modules\Tournament\Domain\Models\Tournament;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class TournamentScheduleService
{
    public function __construct(
        private readonly TournamentAccess $access,
        private readonly RoundRobinGenerator $generator,
    ) {}

    /** @return array<string, mixed> */
    public function preview(Tournament $tournament, Actor $actor, int $legs): array
    {
        $this->access->assertAllows($tournament, $actor, TournamentPermissionEnum::MANAGE_GAMES);
        $this->assertCanGenerate($tournament);
        $entries = $this->activeEntries($tournament);
        $rounds = $this->generator->generate($entries->pluck('id')->all(), $legs);
        $names = $entries->pluck('name', 'id');

        return [
            'legs' => $legs,
            'entries_fingerprint' => $this->fingerprint($tournament),
            'rounds' => collect($rounds)->map(fn (array $round): array => [
                'round' => $round['round'],
                'matches' => collect($round['matches'])->map(fn (array $match): array => [
                    ...$match,
                    'entry_a_name' => $names[$match['entry_a_id']],
                    'entry_b_name' => $names[$match['entry_b_id']],
                ])->all(),
            ])->all(),
        ];
    }

    public function apply(Tournament $tournament, Actor $actor, int $legs, string $fingerprint): void
    {
        DB::transaction(function () use ($tournament, $actor, $legs, $fingerprint): void {
            $locked = Tournament::query()->whereKey($tournament->id)->lockForUpdate()->firstOrFail();
            $this->access->assertAllows($locked, $actor, TournamentPermissionEnum::MANAGE_GAMES);
            $this->assertCanGenerate($locked);
            if (! hash_equals($this->fingerprint($locked), $fingerprint)) {
                throw new InvalidArgumentException('Состав участников изменился. Сформируйте расписание заново.');
            }

            $matches = $locked->matches()->lockForUpdate()->get();
            if ($matches->contains(fn ($match): bool => $match->game_id !== null)) {
                throw new InvalidArgumentException('Нельзя заменить схему после назначения хотя бы одного матча на игру.');
            }

            $rounds = $this->generator->generate($this->activeEntries($locked)->pluck('id')->all(), $legs);
            $matches->each->forceDelete();
            $sequence = 1;
            foreach ($rounds as $round) {
                foreach ($round['matches'] as $match) {
                    $locked->matches()->create([...$match, 'round' => $round['round'], 'sequence' => $sequence++]);
                }
            }
        });
    }

    private function assertCanGenerate(Tournament $tournament): void
    {
        if ($tournament->status === TournamentStatusEnum::CANCELLED) {
            throw new InvalidArgumentException('Для отменённого турнира нельзя формировать расписание.');
        }
    }

    private function activeEntries(Tournament $tournament)
    {
        return $tournament->entries()
            ->where('status', TournamentEntryStatusEnum::ACTIVE->value)
            ->orderByRaw('CASE WHEN position IS NULL THEN 1 ELSE 0 END')
            ->orderBy('position')
            ->orderBy('id')
            ->get();
    }

    private function fingerprint(Tournament $tournament): string
    {
        $values = $this->activeEntries($tournament)
            ->map(fn ($entry): string => implode(':', [
                $entry->id,
                hash('sha256', $entry->name),
                $entry->status->value,
                $entry->position ?? '-',
                $entry->updated_at?->format('U.u'),
            ]))
            ->join('|');

        return hash('sha256', $tournament->id.'|'.$values);
    }
}
