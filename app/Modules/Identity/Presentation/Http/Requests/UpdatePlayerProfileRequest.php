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
        $profileGender = PlayerCharacterAppearanceOptions::normalizeGender(
            $this->user()?->profile?->gender?->value,
        );
        $allowedHairstyles = PlayerCharacterAppearanceOptions::hairstylesForGender($profileGender);
        $allowedFacialHair = $profileGender === 'female'
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
            'character' => ['nullable', 'array:skin_tone,hairstyle,hair_color,facial_hair,piercings,tattoos,tattoo_note,uniform_kit'],
            'character.skin_tone' => ['required_with:character', Rule::in(PlayerCharacterAppearanceOptions::SKIN_TONES)],
            'character.hairstyle' => ['required_with:character', Rule::in($allowedHairstyles)],
            'character.hair_color' => ['required_with:character', Rule::in(PlayerCharacterAppearanceOptions::HAIR_COLORS)],
            'character.facial_hair' => ['required_with:character', Rule::in($allowedFacialHair)],
            'character.piercings' => ['nullable', 'array', 'max:4'],
            'character.piercings.*' => ['required', 'distinct', Rule::in(PlayerCharacterAppearanceOptions::PIERCINGS)],
            'character.tattoos' => ['nullable', 'array', 'max:9'],
            'character.tattoos.*' => ['required', 'distinct', Rule::in(PlayerCharacterAppearanceOptions::TATTOO_LOCATIONS)],
            'character.tattoo_note' => ['nullable', 'string', 'max:500'],
            'character.uniform_kit' => ['nullable', Rule::in(PlayerCharacterAppearanceOptions::UNIFORM_KITS)],
            'character_face_photo_data' => ['nullable', 'string', 'max:1500000'],
            'character_render_requested' => ['nullable', 'boolean'],
            'character_render_mode' => ['nullable', Rule::in(['success', 'error'])],
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
            'piercings' => array_values($character['piercings'] ?? []),
            'tattoos' => array_values($character['tattoos'] ?? []),
            'tattoo_note' => trim((string) ($character['tattoo_note'] ?? '')),
            'uniform_kit' => (string) ($character['uniform_kit'] ?? 'mskba_home'),
        ];
    }

    public function characterFacePhotoData(): ?string
    {
        return $this->filled('character_face_photo_data')
            ? $this->string('character_face_photo_data')->toString()
            : null;
    }

    public function characterRenderRequested(): bool
    {
        return $this->boolean('character_render_requested');
    }

    public function characterRenderMode(): string
    {
        return $this->validated('character_render_mode') === 'error' ? 'error' : 'success';
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
