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

        $this->assertStringContainsString('LAST supplied image is the selected logo reference', $withLogo);
        $this->assertStringContainsString('Copy that supplied selected logo', $withLogo);
        $this->assertStringContainsString('Do NOT redesign, stylize, simplify, reinterpret, substitute, replace', $withLogo);
        $this->assertStringContainsString('If the logo is a QR code, preserve the QR module pattern', $withLogo);
        $this->assertStringContainsString('must visually match the LAST supplied image', $withLogo);
        $this->assertStringContainsString('Do not place a selected logo on the uniform', $withoutLogo);
        $this->assertStringNotContainsString('Copy that supplied team logo', $withoutLogo);
    }

    public function test_removed_body_fat_setting_does_not_affect_ai_prompt(): void
    {
        $builder = new PlayerCharacterGenerationPromptBuilder();

        $withHistoricalValue = $builder->build([
            'body_type' => 'large',
            'appearance' => ['adiposity' => 'elevated', 'shoes' => 'orange'],
        ]);
        $withoutHistoricalValue = $builder->build([
            'body_type' => 'large',
            'appearance' => ['shoes' => 'orange'],
        ]);

        $this->assertSame($withoutHistoricalValue, $withHistoricalValue);
        $this->assertStringContainsString('fuller build', $withHistoricalValue);
        $this->assertStringContainsString('"shoes": "orange"', $withHistoricalValue);
        $this->assertStringNotContainsString('visual_body_fat_level', $withHistoricalValue);
    }

    public function test_new_normal_body_type_is_described_in_ai_prompt(): void
    {
        $prompt = (new PlayerCharacterGenerationPromptBuilder())->build([
            'body_type' => 'normal',
        ]);

        $this->assertStringContainsString('normal build', $prompt);
    }


}
