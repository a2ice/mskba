<?php

namespace App\Modules\Tournament\Application\UseCases;

use App\Modules\Event\Domain\Enums\GameFormatEnum;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\Tournament\Domain\Enums\TournamentRecruitmentModeEnum;
use App\Modules\Tournament\Domain\Enums\TournamentStatusEnum;
use App\Modules\Tournament\Domain\Models\Tournament;
use App\Support\Text\CyrillicTransliterator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class CreateTournamentHandler
{
    public function __construct(private readonly CyrillicTransliterator $transliterator) {}

    /** @param array<string, mixed> $data */
    public function handle(Actor $actor, array $data): Tournament
    {
        if ($actor->user_id === null || $actor->user?->status !== UserStatusEnum::CONFIRMED) {
            throw new InvalidArgumentException('Создавать турниры может только подтверждённый пользователь.');
        }

        [$startsOn, $endsOn] = $this->period($data);

        $format = isset($data['format']) ? GameFormatEnum::from($data['format']) : null;
        $recruitmentMode = $format === GameFormatEnum::STREETBALL_1X1
            ? TournamentRecruitmentModeEnum::INDIVIDUAL_DRAFT
            : TournamentRecruitmentModeEnum::from($data['recruitment_mode'] ?? TournamentRecruitmentModeEnum::PREFORMED_TEAMS->value);

        return DB::transaction(fn (): Tournament => Tournament::query()->create([
            'created_by_actor_id' => $actor->id,
            'title' => $data['title'],
            'alias' => $this->alias($data['alias'] ?? $data['title']),
            'status' => TournamentStatusEnum::CONFIRMED,
            'status_comment' => null,
            'starts_on' => $startsOn,
            'ends_on' => $endsOn,
            'short_description' => $data['short_description'] ?? null,
            'full_description' => $data['full_description'] ?? null,
            'format' => $format,
            'recruitment_mode' => $recruitmentMode,
        ]));
    }

    /** @param array<string, mixed> $data @return array{CarbonImmutable, CarbonImmutable|null} */
    private function period(array $data): array
    {
        $startsOn = CarbonImmutable::parse($data['starts_on'])->startOfDay();
        $endsOn = isset($data['ends_on']) ? CarbonImmutable::parse($data['ends_on'])->startOfDay() : null;
        if ($endsOn?->lessThan($startsOn)) {
            throw new InvalidArgumentException('Окончание турнира не может быть раньше начала.');
        }

        return [$startsOn, $endsOn];
    }

    private function alias(string $value): string
    {
        return Str::slug($this->transliterator->transliterate($value)) ?: 'tournament';
    }
}
