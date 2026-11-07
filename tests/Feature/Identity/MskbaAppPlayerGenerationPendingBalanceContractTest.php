<?php

namespace Tests\Feature\Identity;

use PHPUnit\Framework\TestCase;

final class MskbaAppPlayerGenerationPendingBalanceContractTest extends TestCase
{
    public function test_reserving_last_available_generation_does_not_show_insufficient_funds_until_terminal_result(): void
    {
        $client = file_get_contents(__DIR__.'/../../../resources/themes/mskba_app/js/player-profile-workspace.js');

        // Check affordability BEFORE the paid mutation, without re-quoting
        // immediately after its pending response reserves the entire balance.
        self::assertStringContainsString("const quote = await refreshOffer();", $client);
        self::assertStringContainsString("if (!quote || !quote.can_generate", $client);
        self::assertStringContainsString("reservationPending = true;", $client);
        self::assertStringContainsString("terminalResultReceived = await pollGeneration(", $client);
        self::assertStringNotContainsString(
            "await refreshOffer().catch(() => {});\n                await pollGeneration(",
            $client,
        );
        self::assertStringContainsString(
            "if (!reservationPending || terminalResultReceived)",
            $client,
        );
        self::assertStringContainsString("error.generationTerminal === true", $client);
        self::assertStringContainsString("return false;", $client);
        self::assertStringContainsString("canGenerate = false;", $client);
        self::assertStringContainsString("setGenerationBusy(false);", $client);
    }

    public function test_submit_button_shows_accessible_spinner_only_during_generation(): void
    {
        $root = __DIR__.'/../../../resources/themes/mskba_app/';
        $markup = file_get_contents($root.'views/pages/account/partials/player-generation-fields.blade.php');
        $client = file_get_contents($root.'js/player-profile-workspace.js');
        $styles = file_get_contents($root.'css/player-generation-options.css');

        self::assertStringContainsString('data-player-generate aria-busy="false"', $markup);
        self::assertStringContainsString('data-player-generate-spinner aria-hidden="true" hidden', $markup);
        self::assertStringContainsString('>Сгенерировать</span>', $markup);
        self::assertStringContainsString('setGenerationBusy(true);', $client);
        self::assertStringContainsString("loadingIcon.hidden = !busy;", $client);
        self::assertStringContainsString("generate.setAttribute('aria-busy', String(busy))", $client);
        self::assertStringContainsString("generate.disabled = busy || !canGenerate", $client);
        self::assertStringContainsString('.app-player-workspace__generate-spinner[hidden]', $styles);
        self::assertStringContainsString('@keyframes app-player-generate-spin', $styles);
        self::assertStringContainsString('@media(prefers-reduced-motion:reduce)', $styles);
    }
}
