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

    public function test_fonts_and_sidebar_palette_render_with_safe_per_theme_overrides(): void
    {
        $theme = new DesignTheme(config('design_palettes.day'));
        $theme->body_font = 'onest';
        $theme->heading_font = 'commissioner';
        $theme->widget_glow_color = '#BCDDF6';
        $theme->widget_glow_strength = 20;
        $theme->sidebar_background = '#FFFFFF';
        $theme->sidebar_hover = '#E8F0F8';
        $theme->sidebar_active_background = '#FFF4EB';
        $theme->sidebar_active_text = '#BD4F00';

        $compiler = new DesignThemeCss;
        $css = $compiler->render($theme);
        $this->assertStringContainsString('--theme-font-family: "Onest MSKBA"', $css);
        $this->assertStringContainsString('--theme-heading-font-family: "Commissioner MSKBA"', $css);
        $this->assertStringContainsString('--surface-corner-glow: #BCDDF633;', $css);
        $this->assertStringContainsString('--sidebar-hover: #E8F0F8;', $css);
        $this->assertStringContainsString('--sidebar-active-background: #FFF4EB;', $css);

        $theme->body_font = 'malicious-css-value';
        $this->expectException(ValidationException::class);
        $compiler->render($theme);
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
