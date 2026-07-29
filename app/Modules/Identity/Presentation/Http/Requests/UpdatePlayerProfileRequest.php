<?php

namespace App\Modules\Identity\Presentation\Http\Requests;

use App\Modules\Identity\Domain\Enums\Participation\PlayerBodyTypeEnum;
use App\Modules\Identity\Domain\Enums\Participation\PlayerPositionEnum;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleEnum;
use App\Modules\Identity\Domain\Models\Participation\PlayerSelfAssessment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdatePlayerProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasActiveRole(UserParticipationRoleEnum::PLAYER->value) === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = [
            'height_cm' => ['nullable', 'integer', 'between:150,220'],
            'weight_kg' => ['nullable', 'integer', 'between:40,120'],
            'body_type' => ['nullable', Rule::enum(PlayerBodyTypeEnum::class)],
            'positions' => ['nullable', 'array', 'max:5'],
            'positions.*' => ['required', 'distinct', Rule::enum(PlayerPositionEnum::class)],
            'experience_started_year' => [
                'nullable',
                'integer',
                'between:'.max(1960, now()->year - 70).','.(now()->year - 10),
            ],
            'comment' => ['nullable', 'string', 'max:1000'],
            'self_assessment' => ['nullable', 'array:'.implode(',', array_keys(PlayerSelfAssessment::SKILLS))],
            'redirect_to' => ['nullable', Rule::in(['role', 'account'])],
        ];

        foreach (array_keys(PlayerSelfAssessment::SKILLS) as $skill) {
            $rules['self_assessment.'.$skill] = ['nullable', 'integer', 'between:1,10'];
        }

        return $rules;
    }

    /**
     * @return array<string, mixed>
     */
    public function profileData(): array
    {
        return [
            'height_cm' => $this->nullableInteger('height_cm'),
            'weight_kg' => $this->nullableInteger('weight_kg'),
            'body_type' => $this->filled('body_type')
                ? PlayerBodyTypeEnum::from($this->string('body_type')->toString())
                : null,
            'experience_started_year' => $this->nullableInteger('experience_started_year'),
            'comment' => $this->filled('comment') ? trim($this->string('comment')->toString()) : null,
        ];
    }

    /**
     * @return array<int, PlayerPositionEnum>
     */
    public function positions(): array
    {
        return collect($this->validated('positions', []))
            ->map(fn (string $position): PlayerPositionEnum => PlayerPositionEnum::from($position))
            ->all();
    }

    /**
     * @return array<string, int|null>
     */
    public function selfAssessment(): array
    {
        return collect(PlayerSelfAssessment::SKILLS)
            ->mapWithKeys(fn (string $label, string $skill): array => [
                $skill => $this->nullableInteger('self_assessment.'.$skill),
            ])
            ->all();
    }

    public function shouldClose(): bool
    {
        return $this->validated('redirect_to') === 'account';
    }

    private function nullableInteger(string $key): ?int
    {
        return $this->filled($key) ? (int) $this->input($key) : null;
    }
}
