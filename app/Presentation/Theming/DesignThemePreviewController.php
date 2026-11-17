<?php

namespace App\Presentation\Theming;

use App\Filament\Backoffice\Resources\DesignThemes\DesignThemeResource;
use Illuminate\Http\Response;

final class DesignThemePreviewController
{
    public function __invoke(DesignTheme $theme, DesignThemeCss $css): Response
    {
        abort_unless(DesignThemeResource::canViewAny(), 403);

        return response()->view('design-themes.preview', [
            'theme' => $theme,
            'compiledCss' => $css->render($theme),
        ])->header('Cache-Control', 'private, no-store')
            ->header('X-Robots-Tag', 'noindex, nofollow');
    }
}
