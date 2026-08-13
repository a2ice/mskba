<?php

namespace App\Modules\Tournament\Application\Services;

use App\Modules\Identity\Domain\Models\Participation\PlayerSelfAssessment;
use App\Modules\Identity\Domain\Models\User;

final class TournamentPlayerCharacteristics
{
    /** @return array{position:string, physical:array<string, string>, game:array<string, string>} */
    public function forUser(User $user): array
    {
        $user->loadMissing(['playerProfile.positions', 'playerProfile.selfAssessment']);
        $profile = $user->playerProfile;
        $assessment = $profile?->selfAssessment;

        return [
            'position' => $profile?->positions->pluck('position')->map(fn ($position) => $position->label())->join(', ') ?: '—',
            'physical' => [
                'Рост' => $profile?->height_cm !== null ? $profile->height_cm.' см' : '—',
                'Вес' => $profile?->weight_kg !== null ? rtrim(rtrim((string) $profile->weight_kg, '0'), '.').' кг' : '—',
                'Опыт' => $profile?->experience_years !== null ? $profile->experience_years.' лет' : '—',
                'Телосложение' => $profile?->body_type?->label() ?? '—',
            ],
            'game' => collect(PlayerSelfAssessment::SKILLS)
                ->mapWithKeys(fn (string $label, string $key): array => [$label => $assessment?->{$key} !== null ? (string) $assessment->{$key} : '—'])
                ->all(),
        ];
    }
}
