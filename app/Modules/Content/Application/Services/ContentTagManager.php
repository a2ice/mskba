<?php

namespace App\Modules\Content\Application\Services;

use App\Modules\Content\Domain\Models\ContentItem;
use App\Modules\Content\Domain\Models\ContentTag;
use Illuminate\Support\Str;

final class ContentTagManager
{
    public const MAX_TAGS = 30;

    public const MAX_TAG_LENGTH = 100;

    public function sync(ContentItem $content, ?string $input): void
    {
        $names = collect(preg_split('/[,;\n]+/u', (string) $input) ?: [])
            ->map(fn (string $name): string => $this->normalize($name))
            ->filter(fn (string $name): bool => $name !== '' && mb_strlen($name) <= self::MAX_TAG_LENGTH)
            ->unique()
            ->take(self::MAX_TAGS)
            ->values();

        $tagIds = $names->map(function (string $name): int {
            return (int) ContentTag::query()->firstOrCreate(
                ['normalized_name' => $name],
                ['name' => $name],
            )->id;
        })->all();

        $content->tags()->sync($tagIds);
    }

    private function normalize(string $value): string
    {
        $value = Str::lower(trim($value));
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return trim($value, " \t\n\r\0\x0B,;.");
    }
}
