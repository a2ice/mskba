<?php

namespace Tests\Feature\Backoffice;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ContractTablePresentationTest extends TestCase
{
    #[Test]
    public function contract_tabs_use_a_loaded_scoped_stylesheet_instead_of_uncompiled_tailwind_utilities(): void
    {
        $panel = file_get_contents(app_path('Providers/Filament/BackofficePanelProvider.php'));
        $template = file_get_contents(resource_path('views/filament/backoffice/related-contracts.blade.php'));
        $styles = file_get_contents(public_path('css/backoffice-contracts.css'));

        $this->assertIsString($panel);
        $this->assertIsString($template);
        $this->assertIsString($styles);

        $this->assertStringContainsString("asset('css/backoffice-contracts.css')", $panel);
        $this->assertStringContainsString('mskba-contract-panel', $template);
        $this->assertStringContainsString('mskba-contract-table__status', $template);
        $this->assertStringContainsString('data-label="Контракт"', $template);
        $this->assertStringContainsString('data-label="Срок"', $template);
        $this->assertStringContainsString('data-label="Действие"', $template);
        $this->assertStringContainsString('max-width: 1050px', $styles);
        $this->assertStringContainsString('grid-template-columns: repeat(2, minmax(0, 1fr))', $styles);
        $this->assertStringContainsString('content: attr(data-label)', $styles);
        $this->assertStringContainsString('overflow-wrap: anywhere', $styles);
        $this->assertStringNotContainsString('class="w-full text-sm"', $template);
        $this->assertStringNotContainsString('class="p-3"', $template);
    }

    #[Test]
    public function contract_history_uses_the_same_mobile_card_structure(): void
    {
        $history = file_get_contents(resource_path('views/filament/backoffice/contract-history.blade.php'));

        $this->assertIsString($history);
        $this->assertStringContainsString('mskba-contract-history', $history);
        $this->assertStringContainsString('mskba-contract-table-wrap', $history);
        $this->assertStringContainsString('data-label="Дата"', $history);
        $this->assertStringContainsString('data-label="Основание"', $history);
        $this->assertStringNotContainsString('class="w-full text-sm"', $history);
    }
}
