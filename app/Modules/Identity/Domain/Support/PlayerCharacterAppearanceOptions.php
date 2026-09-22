<?php

namespace App\Modules\Identity\Domain\Support;

final class PlayerCharacterAppearanceOptions
{
    public const VERSION = 4;

    public const GENDERS = ['male', 'female'];

    public const RENDER_MODES = ['2d', '3d'];

    public const CHEST_VOLUMES = [
        'small',
        'medium',
        'large',
        'full',
    ];

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

    public const UNIFORM_KITS = [
        'mskba_home',
        'mskba_light',
        'street_black',
        'city_night',
    ];

    public const SHOES = [
        'white',
        'black',
    ];

    public const ATTRIBUTES = [
        'elbow_left',
        'elbow_right',
        'elbow_both',
        'wristbands',
        'knee_pads',
        'headband',
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
     * @return array<string, int|string|null>
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
            'uniform_kit' => 'mskba_home',
            'shoes' => 'white',
            'attributes' => [],
            'chest_volume' => $gender === 'female' ? 'medium' : null,
        ];
    }
}
