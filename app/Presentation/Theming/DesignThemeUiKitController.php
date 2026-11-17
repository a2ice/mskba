<?php

namespace App\Presentation\Theming;

use App\Filament\Backoffice\Resources\DesignThemes\DesignThemeResource;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class DesignThemeUiKitController
{
    public function __invoke(Request $request, DesignTheme $theme, DesignThemeCss $css): Response
    {
        abort_unless(DesignThemeResource::canView($theme), 403);

        return response()->view('design-themes.ui-kit', [
            'theme' => $theme,
            'compiledCss' => $css->render($theme),
            'themes' => DesignTheme::query()->orderBy('name')->get(['id', 'name', 'mode', 'slug']),
            'embedded' => $request->boolean('embed'),
        ])->header('Cache-Control', 'private, no-store')
            ->header('X-Robots-Tag', 'noindex, nofollow')
            ->header('Referrer-Policy', 'same-origin')
            ->header('X-Frame-Options', 'SAMEORIGIN');
    }
}
