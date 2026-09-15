<?php

namespace App\Modules\Team\Application\Services;

use InvalidArgumentException;
use RuntimeException;

final class TeamLogoPresets
{
    /** @return list<string> */
    public static function ids(): array
    {
        return array_map(fn (int $number): string => sprintf('crest-%02d', $number), range(0, 14));
    }

    /** @return array<string, string> */
    public function options(): array
    {
        return array_combine(self::ids(), array_map(
            fn (string $id): string => asset("images/tournament-team-logos/{$id}.webp"),
            self::ids(),
        ));
    }

    public function contents(string $id): string
    {
        if (! in_array($id, self::ids(), true)) {
            throw new InvalidArgumentException('Выберите логотип из списка.');
        }

        $contents = file_get_contents(public_path("images/tournament-team-logos/{$id}.webp"));
        if ($contents === false) {
            throw new RuntimeException('Не удалось прочитать выбранный логотип.');
        }

        return $contents;
    }
}
