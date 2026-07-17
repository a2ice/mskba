<?php

namespace App\Modules\Venue\Presentation\Http\Requests\Concerns;

use Illuminate\Support\Collection;

trait InteractsWithVenueTags
{
    /**
     * @return array<int, string>
     */
    public function tagNames(): array
    {
        return $this->parseTagNames($this->validated('tags'))
            ->take(20)
            ->all();
    }

    protected function addVenueTagValidation(mixed $validator): void
    {
        $validator->after(function ($validator): void {
            $tags = $this->parseTagNames($this->input('tags'));

            if ($tags->count() > 20) {
                $validator->errors()->add('tags', 'Можно указать не более 20 тегов.');
            }

            if ($tags->contains(fn (string $tag): bool => mb_strlen($tag) > 80)) {
                $validator->errors()->add('tags', 'Каждый тег должен быть не длиннее 80 символов.');
            }
        });
    }

    private function parseTagNames(mixed $value): Collection
    {
        if (! is_string($value)) {
            return collect();
        }

        return collect(preg_split('/[,;\n]+/u', $value) ?: [])
            ->map(fn (string $tag): string => trim($tag, " \t\n\r\0\x0B#"))
            ->filter()
            ->unique(fn (string $tag): string => mb_strtolower($tag))
            ->values();
    }
}
