<?php

namespace App\Modules\Content\Application\Services;

use App\Modules\Venue\Domain\Models\Venue;
use Illuminate\Support\Str;

final class EmbeddedEntityShortcodeRenderer
{
    /** @var array<string, string> */
    private array $replacements = [];

    public function extract(string $source): string
    {
        $this->replacements = [];

        $patterns = [
            '/\[popup\s+type=["\']venue["\']\s+id=["\'](\d+)["\']\s+view=["\']short["\']\]([^\[]+)\[\/popup\]/iu',
            '/\[sc:popup;short_info;venue;(\d+)\]([^\[]+)\[\/sc\]/iu',
        ];

        foreach ($patterns as $pattern) {
            $source = preg_replace_callback($pattern, function (array $matches): string {
                $token = 'MSKBAENTITY'.Str::upper(Str::random(24)).'TOKEN';
                $this->replacements[$token] = $this->venueTrigger((int) $matches[1], $matches[2]);

                return $token;
            }, $source) ?? $source;
        }

        return $source;
    }

    public function restore(string $rendered): string
    {
        return strtr($rendered, $this->replacements);
    }

    private function venueTrigger(int $venueId, string $label): string
    {
        $text = trim(strip_tags($label));

        if ($text === '') {
            return '';
        }

        $venue = Venue::query()->find($venueId);

        if (! $venue) {
            return e($text);
        }

        return sprintf(
            '<button type="button" class="embedded-entity-link js-handler" data-handler="modal" data-modal-action="open" data-modal-target="embedded-entity-preview" data-entity-preview-trigger data-entity-type="venue" data-entity-preview-url="%s">%s</button>',
            e(route('venues.preview', $venue->routeIdentifier(), false)),
            e($text),
        );
    }
}
