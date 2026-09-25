<?php

namespace Tests\Unit\Ai;

use App\Modules\Ai\Application\Services\PlayerCharacterGenerationPromptBuilder;
use PHPUnit\Framework\TestCase;

final class PlayerCharacterGenerationPromptBuilderTest extends TestCase
{
    public function test_prompt_explicitly_requires_empty_hands_and_no_props(): void
    {
        $prompt = (new PlayerCharacterGenerationPromptBuilder())->build([
            'gender' => 'male',
            'appearance' => [
                'shoes' => 'white',
                'attributes' => [],
            ],
        ]);

        $this->assertStringContainsString('Full body', $prompt);
        $this->assertStringContainsString('Front view', $prompt);
        $this->assertStringContainsString('Standing upright', $prompt);
        $this->assertStringContainsString('neutral pose', $prompt);
        $this->assertStringContainsString('hands empty', $prompt);
        $this->assertStringContainsString('NO basketball', $prompt);
        $this->assertStringContainsString('NO props', $prompt);
        $this->assertStringContainsString('NO extra accessories unless explicitly selected', $prompt);
    }

    public function test_prompt_uses_team_logo_only_when_explicitly_enabled(): void
    {
        $builder = new PlayerCharacterGenerationPromptBuilder();

        $withLogo = $builder->build([
            'team' => [
                'name' => 'MSKBA Test',
                'colors' => ['home_primary' => '#ff6600'],
                'with_logo' => true,
            ],
        ]);
        $withoutLogo = $builder->build([
            'team' => [
                'name' => 'MSKBA Test',
                'colors' => ['home_primary' => '#ff6600'],
                'with_logo' => false,
            ],
        ]);

        $this->assertStringContainsString('LAST supplied image is the team-logo reference', $withLogo);
        $this->assertStringContainsString('Copy that supplied team logo', $withLogo);
        $this->assertStringContainsString('Do NOT redesign, stylize, simplify, reinterpret, substitute, replace', $withLogo);
        $this->assertStringContainsString('If the logo is a QR code, preserve the QR module pattern', $withLogo);
        $this->assertStringContainsString('must visually match the LAST supplied image', $withLogo);
        $this->assertStringContainsString('Do not place a team logo on the uniform', $withoutLogo);
        $this->assertStringNotContainsString('Copy that supplied team logo', $withoutLogo);
    }
}
