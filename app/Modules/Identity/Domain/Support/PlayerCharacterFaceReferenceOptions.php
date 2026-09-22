<?php

namespace App\Modules\Identity\Domain\Support;

use InvalidArgumentException;

final class PlayerCharacterFaceReferenceOptions
{
    public const MAX_OUTPUT_DIMENSION = 512;

    public const AI_VALIDATED_REFERENCE = 'player-character-ai-validated-v1';

    public const SLOTS = ['front', 'left', 'right'];

    public const COLLECTIONS = [
        'front' => 'player_character_face_front',
        'left' => 'player_character_face_left',
        'right' => 'player_character_face_right',
    ];

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            'front' => 'Анфас',
            'left' => 'Слева',
            'right' => 'Справа',
        ];
    }

    public static function collectionForSlot(string $slot): string
    {
        if (! array_key_exists($slot, self::COLLECTIONS)) {
            throw new InvalidArgumentException('Неизвестный ракурс лица.');
        }

        return self::COLLECTIONS[$slot];
    }

    public static function slotForCollection(string $collection): ?string
    {
        $slot = array_search($collection, self::COLLECTIONS, true);

        return is_string($slot) ? $slot : null;
    }

    /**
     * @return array<int, string>
     */
    public static function collections(): array
    {
        return array_values(self::COLLECTIONS);
    }
}
