<?php

namespace App\Modules\Identity\Presentation\Http\Requests;

use App\Modules\Identity\Domain\Enums\Participation\PlayerBodyTypeEnum;
use App\Modules\Identity\Domain\Enums\Participation\PlayerPositionEnum;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleEnum;
use App\Modules\Identity\Domain\Models\Participation\PlayerSelfAssessment;
use App\Modules\Identity\Domain\Support\PlayerCharacterAppearanceOptions;
use App\Modules\Identity\Domain\Support\PlayerCharacterFaceReferenceOptions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
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
        $profileGender = PlayerCharacterAppearanceOptions::normalizeGender(
            $this->user()?->profile?->gender?->value,
        );
        $allowedHairstyles = PlayerCharacterAppearanceOptions::hairstylesForGender($profileGender);
        $allowedFacialHair = $profileGender === 'female'
            ? ['none']
            : PlayerCharacterAppearanceOptions::FACIAL_HAIR;

        $rules = [
            'mutation' => ['nullable', Rule::in(['render_mode', 'face_reference', 'generate_2d'])],
            'render_mode' => [
                'nullable',
                Rule::requiredIf(fn (): bool => $this->input('mutation') === 'render_mode'),
                Rule::in(PlayerCharacterAppearanceOptions::RENDER_MODES),
            ],
            'face_reference_slot' => [
                'nullable',
                Rule::requiredIf(fn (): bool => $this->input('mutation') === 'face_reference'),
                Rule::in(PlayerCharacterFaceReferenceOptions::SLOTS),
            ],
            'face_reference' => [
                'nullable',
                Rule::requiredIf(fn (): bool => $this->input('mutation') === 'face_reference'),
                'file',
                'mimes:jpg,jpeg,png,webp',
                'max:5120',
            ],
            'generation_team_id' => ['nullable', 'integer'],
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
            'character' => ['nullable', 'array:skin_tone,hairstyle,hair_color,facial_hair,uniform_kit,shoes,attributes,chest_volume'],
            'character.skin_tone' => ['required_with:character', Rule::in(PlayerCharacterAppearanceOptions::SKIN_TONES)],
            'character.hairstyle' => ['required_with:character', Rule::in($allowedHairstyles)],
            'character.hair_color' => ['required_with:character', Rule::in(PlayerCharacterAppearanceOptions::HAIR_COLORS)],
            'character.facial_hair' => ['required_with:character', Rule::in($allowedFacialHair)],
            'character.uniform_kit' => ['required_with:character', Rule::in(PlayerCharacterAppearanceOptions::UNIFORM_KITS)],
            'character.shoes' => ['nullable', Rule::in(PlayerCharacterAppearanceOptions::SHOES)],
            'character.attributes' => ['nullable', 'array', 'max:6'],
            'character.attributes.*' => ['required', 'distinct', Rule::in(PlayerCharacterAppearanceOptions::ATTRIBUTES)],
            'character.chest_volume' => ['nullable', Rule::in(PlayerCharacterAppearanceOptions::CHEST_VOLUMES)],
            'redirect_to' => ['nullable', Rule::in(['role', 'account'])],
        ];

        foreach (array_keys(PlayerSelfAssessment::SKILLS) as $skill) {
            $rules['self_assessment.'.$skill] = ['nullable', 'integer', 'between:1,10'];
        }

        return $rules;
    }

    public function mutation(): ?string
    {
        $mutation = $this->validated('mutation');

        return is_string($mutation) && $mutation !== '' ? $mutation : null;
    }

    public function renderMode(): ?string
    {
        $renderMode = $this->validated('render_mode');

        return is_string($renderMode) && $renderMode !== '' ? $renderMode : null;
    }

    public function faceReferenceSlot(): ?string
    {
        $slot = $this->validated('face_reference_slot');

        return is_string($slot) && $slot !== '' ? $slot : null;
    }

    public function faceReferenceFile(): ?UploadedFile
    {
        $file = $this->file('face_reference');

        return $file instanceof UploadedFile ? $file : null;
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
     * @return array<string, mixed>|null
     */
    public function characterAppearance(): ?array
    {
        if (! $this->has('character')) {
            return null;
        }

        $character = $this->validated('character');
        $profileGender = PlayerCharacterAppearanceOptions::normalizeGender(
            $this->user()?->profile?->gender?->value,
        );

        return [
            'version' => PlayerCharacterAppearanceOptions::VERSION,
            'gender' => $profileGender,
            'skin_tone' => (string) $character['skin_tone'],
            'hairstyle' => (string) $character['hairstyle'],
            'hair_color' => (string) $character['hair_color'],
            'facial_hair' => $profileGender === 'female'
                ? 'none'
                : (string) $character['facial_hair'],
            'uniform_kit' => (string) $character['uniform_kit'],
            'shoes' => (string) ($character['shoes'] ?? 'white'),
            'attributes' => array_values(array_intersect(
                PlayerCharacterAppearanceOptions::ATTRIBUTES,
                (array) ($character['attributes'] ?? []),
            )),
            'chest_volume' => $profileGender === 'female'
                ? ($character['chest_volume'] ?? null)
                : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function generationOptions(): array
    {
        return [
            'height_cm' => $this->nullableInteger('height_cm'),
            'weight_kg' => $this->nullableInteger('weight_kg'),
            'body_type' => $this->filled('body_type')
                ? $this->string('body_type')->toString()
                : null,
            'team_id' => $this->filled('generation_team_id')
                ? (int) $this->input('generation_team_id')
                : null,
            'character' => $this->characterAppearance(),
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
