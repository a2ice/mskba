<?php

namespace Tests\Feature\Backoffice;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ResponsiveTablesAuditTest extends TestCase
{
    /**
     * The Filament CSS bundle does not necessarily include Tailwind utilities
     * used by custom Blade views. Every custom table therefore needs an
     * explicitly styled layout: mobile cards or a contained wide scroller.
     */
    #[Test]
    public function all_custom_backoffice_tables_use_stable_responsive_components(): void
    {
        $directories = [
            resource_path('views/filament/backoffice'),
            resource_path('views/livewire/backoffice'),
        ];
        $tables = 0;

        foreach ($directories as $directory) {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            );

            foreach ($files as $file) {
                if (! str_ends_with($file->getFilename(), '.blade.php')) {
                    continue;
                }

                $source = file_get_contents($file->getPathname());
                $this->assertIsString($source);

                preg_match_all('/<table\\b[^>]*>/i', $source, $found);
                foreach ($found[0] as $tag) {
                    ++$tables;
                    $this->assertMatchesRegularExpression(
                        '/class=["\'][^"\']*\\b(?:mskba-bo-table__table|mskba-contract-table)\\b/i',
                        $tag,
                        'Unstyled custom table in '.$file->getPathname(),
                    );
                }
            }
        }

        $this->assertGreaterThanOrEqual(10, $tables, 'Keep custom backoffice tables covered.');
    }

    #[Test]
    public function mobile_contract_cells_have_full_width_and_desktop_widths_are_guarded(): void
    {
        $css = file_get_contents(public_path('css/backoffice-contracts.css'));
        $this->assertIsString($css);

        $desktopStart = strpos($css, '@media (min-width: 1051px)');
        $mobileStart = strpos($css, '@media (max-width: 1050px)');
        $this->assertNotFalse($desktopStart);
        $this->assertNotFalse($mobileStart);
        $this->assertGreaterThan($desktopStart, $mobileStart);

        $desktopSegment = substr($css, $desktopStart, $mobileStart - $desktopStart);
        $mobileSegment = substr($css, $mobileStart);
        $this->assertStringContainsString('td:nth-child(2) { width: 19%; }', $desktopSegment);
        $this->assertStringContainsString('grid-template-columns: minmax(0, 1fr)', $mobileSegment);
        $this->assertStringContainsString('width: 100%;', $mobileSegment);
        $this->assertStringContainsString('word-break: normal;', $mobileSegment);
        $this->assertStringContainsString('min-width: 700px', $css);
    }

    #[Test]
    public function custom_tables_have_independent_styles_loaded_on_every_backoffice_page(): void
    {
        $provider = file_get_contents(app_path('Providers/Filament/BackofficePanelProvider.php'));
        $css = file_get_contents(public_path('css/backoffice-tables.css'));

        $this->assertIsString($provider);
        $this->assertIsString($css);
        $this->assertStringContainsString("asset('css/backoffice-tables.css')", $provider);
        $this->assertStringContainsString('mskba-bo-table--cards', $css);
        $this->assertStringContainsString('mskba-bo-table--scroll', $css);
        $this->assertStringContainsString('content: attr(data-label)', $css);
        $this->assertStringContainsString('overflow-x: auto', $css);
        $this->assertStringContainsString('min-width: var(--bo-table-min-width, 760px)', $css);
    }

    #[Test]
    public function every_custom_mobile_card_has_a_label_for_each_visible_cell(): void
    {
        foreach ([
            'user-system-roles.blade.php',
            'user-venue-context.blade.php',
            'user-participation-roles.blade.php',
            'user-personal-audit.blade.php',
        ] as $view) {
            $source = file_get_contents(resource_path('views/filament/backoffice/'.$view));
            $this->assertIsString($source);
            $this->assertStringContainsString('mskba-bo-table--cards', $source);

            preg_match_all('/<td\\b[^>]*>/i', $source, $matches);
            $this->assertNotEmpty($matches[0], $view);
            foreach ($matches[0] as $tag) {
                $this->assertTrue(
                    str_contains($tag, 'data-label=')
                        || str_contains($tag, 'mskba-bo-table__empty'),
                    $view.' contains an unlabelled cell: '.$tag,
                );
            }
        }
    }
}
