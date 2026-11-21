<?php

namespace App\Presentation\UiStudio;

use App\Presentation\Theming\DesignTheme;
use App\Presentation\Theming\DesignThemeCss;

final class ThemeSnapshot
{
    public function __construct(private readonly DesignThemeCss $compiler) {}

    public function read(): array
    {
        $path = storage_path('app/private/ui-studio/themes.json');
        if (is_file($path)) {
            $data = json_decode(file_get_contents($path), true);
            abort_unless(is_array($data) && ($data['version'] ?? null) === 1
                && is_string($data['exported_at'] ?? null) && is_array($data['themes'] ?? null)
                && count($data['themes']) > 0, 503, 'Invalid theme snapshot');
            foreach ($data['themes'] as $slug => $theme) {
                abort_unless(is_string($slug) && preg_match('/\A[a-z][a-z0-9_-]{1,59}\z/D', $slug)
                    && is_array($theme) && is_string($theme['name'] ?? null)
                    && in_array($theme['mode'] ?? null, ['light', 'dark'], true)
                    && is_string($theme['css'] ?? null) && is_string($theme['js'] ?? null)
                    && ($theme['hash'] ?? null) === hash('sha256', $theme['css']."\0".$theme['js']), 503);
            }

            return $data + ['source' => 'Filament snapshot'];
        }

        $themes = [];
        foreach (config('design_palettes') as $preset) {
            $theme = new DesignTheme($preset);
            $themes[$theme->slug] = $this->entry($theme, '');
        }

        return ['version' => 1, 'exported_at' => null, 'themes' => $themes, 'source' => 'Встроенные пресеты (не актуальные настройки Filament)'];
    }

    public function entry(DesignTheme $theme, string $js): array
    {
        $css = $this->compiler->render($theme);

        return ['name' => $theme->name, 'mode' => $theme->mode, 'css' => $css,
            'js' => $js, 'hash' => hash('sha256', $css."\0".$js)];
    }
}
