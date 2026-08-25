<?php

namespace App\Modules\Identity\Presentation\Http\Requests;

use App\Modules\Identity\Domain\Enums\Participation\PlayerBodyTypeEnum;
use App\Modules\Identity\Domain\Enums\Participation\PlayerPositionEnum;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleEnum;
use App\Modules\Identity\Domain\Models\Participation\PlayerSelfAssessment;
use App\Modules\Identity\Domain\Support\PlayerCharacterAppearanceOptions;
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
        $characterGender = (string) $this->input('character.gender', 'male');
        $allowedHairstyles = PlayerCharacterAppearanceOptions::hairstylesForGender($characterGender);
        $allowedFacialHair = $characterGender === 'female'
            ? ['none']
            : PlayerCharacterAppearanceOptions::FACIAL_HAIR;

        $rules = [
            'height_cm' => ['nullable', 'integer', 'between:150,220'],
            'weight_kg' => ['nullable', 'integer', 'between:40,140'],
            'body_type' => ['nullable', Rule::enum(PlayerBodyTypeEnum::class)],
            'positions' => ['nullable', 'array', 'max:5'],
            'positions.*' => ['required', 'distinct', Rule::enum(PlayerPositionEnum::class)],
            'experience_started_year' => [
                'nullable',
                'integer',
                'between:'.(now()->year - 50).','.(now()->year - 10),
            ],
            'comment' => ['nullable', 'string', 'max:1000'],
            'self_assessment' => ['nullable', 'array:'.implode(',', array_keys(PlayerSelfAssessment::SKILLS))],
            'character' => ['nullable', 'array:gender,skin_tone,hairstyle,hair_color,facial_hair,uniform_kit'],
            'character.gender' => ['required_with:character', Rule::in(PlayerCharacterAppearanceOptions::GENDERS)],
            'character.skin_tone' => ['required_with:character', Rule::in(PlayerCharacterAppearanceOptions::SKIN_TONES)],
            'character.hairstyle' => ['required_with:character', Rule::in($allowedHairstyles)],
            'character.hair_color' => ['required_with:character', Rule::in(PlayerCharacterAppearanceOptions::HAIR_COLORS)],
            'character.facial_hair' => ['required_with:character', Rule::in($allowedFacialHair)],
            'character.uniform_kit' => ['required_with:character', Rule::in(PlayerCharacterAppearanceOptions::UNIFORM_KITS)],
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
     * @return array<string, int|string>|null
     */
    public function characterAppearance(): ?array
    {
        if (! $this->has('character')) {
            return null;
        }

        $character = $this->validated('character');

        return [
            'version' => PlayerCharacterAppearanceOptions::VERSION,
            'gender' => (string) $character['gender'],
            'skin_tone' => (string) $character['skin_tone'],
            'hairstyle' => (string) $character['hairstyle'],
            'hair_color' => (string) $character['hair_color'],
            'facial_hair' => (string) $character['facial_hair'],
            'uniform_kit' => (string) $character['uniform_kit'],
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
