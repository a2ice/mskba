<?php

namespace App\Presentation\Theming;

use Illuminate\Database\QueryException;
use Illuminate\Http\Response;

final class DesignThemeStylesheetController
{
    public function __invoke(string $theme, string $revision, DesignThemeCss $css): Response
    {
        try {
            $record = DesignTheme::query()->where('slug', $theme)
                ->where('is_published', true)->firstOrFail();
        } catch (QueryException $exception) {
            // The fallback page may request its built-in CSS while migrations
            // are still running. No custom or light theme exists yet.
            if ($theme !== 'arena_night') {
                throw $exception;
            }

            $record = app(DesignThemeManager::class)->default();
        }

        abort_unless(hash_equals($css->revision($record), $revision), 404);

        return response($css->render($record), 200, [
            'Content-Type' => 'text/css; charset=UTF-8',
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
