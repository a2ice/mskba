<?php

namespace App\Modules\Identity\Domain\Support;

final class PlayerCharacterAppearanceOptions
{
    public const VERSION = 3;

    public const GENDERS = ['male', 'female'];

    public const SKIN_TONES = [
        'porcelain',
        'light',
        'warm',
        'tan',
        'brown',
        'deep',
    ];

    public const HAIR_COLORS = [
        'black',
        'dark_brown',
        'brown',
        'blond',
        'ginger',
        'gray',
    ];

    public const MALE_HAIRSTYLES = [
        'male_bald',
        'male_buzz',
        'male_fade',
        'male_short',
        'male_curls',
    ];

    public const FEMALE_HAIRSTYLES = [
        'female_ponytail',
        'female_bob',
        'female_long',
        'female_curls',
        'female_braids',
    ];

    public const FACIAL_HAIR = [
        'none',
        'stubble',
        'mustache',
        'goatee',
        'short_beard',
        'full_beard',
    ];

    public const PIERCINGS = [
        'left_ear',
        'right_ear',
        'both_ears',
        'eyebrow',
        'nose',
        'lip',
    ];

    public const TATTOO_LOCATIONS = [
        'left_upper_arm',
        'right_upper_arm',
        'left_forearm',
        'right_forearm',
        'neck',
        'chest',
        'back',
        'left_calf',
        'right_calf',
    ];

    /**
     * Kept for backward-compatible stored payloads. Uniform configuration is not
     * exposed in the image-render modal during Task 124.
     */
    public const UNIFORM_KITS = [
        'mskba_home',
        'mskba_light',
        'street_black',
        'city_night',
    ];

    public static function normalizeGender(?string $gender): string
    {
        return in_array($gender, self::GENDERS, true) ? $gender : 'male';
    }

    /**
     * @return array<int, string>
     */
    public static function hairstylesForGender(string $gender): array
    {
        return self::normalizeGender($gender) === 'female'
            ? self::FEMALE_HAIRSTYLES
            : self::MALE_HAIRSTYLES;
    }

    /**
     * @return array<string, mixed>
     */
    public static function defaults(string $gender = 'male'): array
    {
        $gender = self::normalizeGender($gender);

        return [
            'version' => self::VERSION,
            'gender' => $gender,
            'skin_tone' => 'warm',
            'hairstyle' => $gender === 'female' ? 'female_ponytail' : 'male_fade',
            'hair_color' => 'dark_brown',
            'facial_hair' => 'none',
            'piercings' => [],
            'tattoos' => [],
            'tattoo_note' => '',
            'uniform_kit' => 'mskba_home',
            'face_photo_path' => null,
        ];
    }
}
