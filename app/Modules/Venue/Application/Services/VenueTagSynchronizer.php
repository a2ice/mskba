<?php

namespace App\Modules\Venue\Application\Services;

use App\Modules\Venue\Domain\Models\Venue;
use App\Support\Text\CyrillicTransliterator;
use Illuminate\Support\Str;

final readonly class VenueTagSynchronizer
{
    public function __construct(
        private CyrillicTransliterator $transliterator,
    ) {}

    /**
     * @param  array<int, string>  $tagNames
     */
    public function sync(Venue $venue, array $tagNames): void
    {
        $tags = collect($tagNames)
            ->map(function (string $name): array {
                $name = mb_substr(trim($name), 0, 80);
                $slug = Str::slug($this->transliterator->transliterate($name));

                return [
                    'name' => $name,
                    'slug' => mb_substr($slug !== '' ? $slug : sha1(mb_strtolower($name)), 0, 100),
                ];
            })
            ->filter(fn (array $tag): bool => $tag['name'] !== '')
            ->unique('slug')
            ->values();

        $venue->tags()->delete();
        $venue->tags()->createMany($tags->all());
        $venue->unsetRelation('tags');
    }
}
