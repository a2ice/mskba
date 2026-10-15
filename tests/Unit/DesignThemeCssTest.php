<?php

namespace Tests\Unit;

use App\Presentation\Theming\DesignTheme;
use App\Presentation\Theming\DesignThemeCss;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class DesignThemeCssTest extends TestCase
{
    public function test_both_presets_compile_into_isolated_custom_properties(): void
    {
        $compiler = new DesignThemeCss;

        foreach (config('design_palettes') as $palette) {
            $theme = new DesignTheme($palette);
            $css = $compiler->render($theme);
            $this->assertStringContainsString('--bg:', $css);
            $this->assertStringContainsString('--accent:', $css);
            $this->assertStringContainsString('color-scheme: '.$palette['mode'], $css);
            $this->assertDoesNotMatchRegularExpression('/url\s*\(|@import/i', $css);
            $this->assertMatchesRegularExpression('/\A[a-f0-9]{16}\z/', $compiler->revision($theme));
        }
    }

    public function test_unsafe_css_is_rejected(): void
    {
        $compiler = new DesignThemeCss;
        $colors = config('design_palettes.night.colors');
        foreach ([
            'body { color: red; }',
            ':root { --bg: url(https://example.test/collect); }',
            ':root { --bg: red; } a { opacity: 0; }',
            '@import "https://example.test";',
        ] as $css) {
            try {
                $compiler->validate($colors, $css);
                $this->fail('Unsafe CSS passed validation: '.$css);
            } catch (ValidationException $exception) {
                $this->assertNotEmpty($exception->errors());
            }
        }
    }
}
