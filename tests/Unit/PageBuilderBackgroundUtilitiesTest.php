<?php

namespace Tests\Unit;

use Tests\TestCase;

final class PageBuilderBackgroundUtilitiesTest extends TestCase
{
    public function test_all_background_classes_are_scoped_and_limit_each_family_to_nine(): void
    {
        $css = file_get_contents(resource_path('css/page-builder-backgrounds.css'));
        $this->assertIsString($css);

        preg_match_all('/\\.mskba-page-builder \\.([a-z-]+) \\{ background: ([^;]+); \\}/', $css, $matches);
        $classes = $matches[1];
        $this->assertCount(60, $classes);
        $this->assertCount(60, array_unique($classes));

        foreach (['blue', 'grey', 'orange', 'green', 'red', 'yellow'] as $color) {
            $variants = [
                "bg-light{$color}", "bg-{$color}", "bg-dark{$color}",
                "bg-grad-light{$color}-{$color}", "bg-grad-{$color}-light{$color}",
                "bg-grad-{$color}-dark{$color}", "bg-grad-dark{$color}-{$color}",
                "bg-grad-light{$color}-dark{$color}", "bg-grad-dark{$color}-light{$color}",
            ];
            foreach ($variants as $variant) {
                $this->assertContains($variant, $classes);
            }
            $this->assertCount(9, array_filter($classes, fn (string $class): bool => in_array($class, $variants, true)));
        }

        foreach (['bg-page', 'bg-surface', 'bg-surface-raised', 'bg-field', 'bg-accent', 'bg-transparent'] as $semantic) {
            $this->assertContains($semantic, $classes);
        }
        $this->assertStringContainsString("var(--info,", $css);
        $this->assertStringContainsString("var(--accent,", $css);
        $this->assertStringContainsString("var(--surface-raised,", $css);
        $this->assertStringNotContainsString('!important', $css);
        $this->assertStringContainsString("@import './page-builder-backgrounds.css';", file_get_contents(resource_path('css/page-builder.css')));
    }
}
