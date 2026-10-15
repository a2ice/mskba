<?php

namespace App\Presentation\Theming;

use Illuminate\Http\Response;

final class DesignThemeStylesheetController
{
    public function __invoke(string $theme, string $revision, DesignThemeCss $css): Response
    {
        $record = DesignTheme::query()->where('slug', $theme)
            ->where('is_published', true)->firstOrFail();

        abort_unless(hash_equals($css->revision($record), $revision), 404);

        return response($css->render($record), 200, [
            'Content-Type' => 'text/css; charset=UTF-8',
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
