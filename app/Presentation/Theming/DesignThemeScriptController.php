<?php

namespace App\Presentation\Theming;

use Illuminate\Http\Response;

final class DesignThemeScriptController
{
    public function __invoke(string $theme, string $hash, DesignThemeCodeManager $manager): Response
    {
        abort_unless(preg_match('/\A[a-f0-9]{64}\z/D', $hash) === 1, 404);
        $record = DesignTheme::query()->where('slug', $theme)
            ->where('is_published', true)->firstOrFail();
        $asset = $manager->asset($record, 'js');

        abort_unless($asset?->is_enabled
            && $asset->published_source !== null
            && hash_equals((string) $asset->published_hash, $hash), 404);

        return response((string) $asset->published_source, 200, [
            'Content-Type' => 'text/javascript; charset=UTF-8',
            'Cache-Control' => 'public, max-age=0, must-revalidate',
            'ETag' => '"'.$hash.'"',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }
}
