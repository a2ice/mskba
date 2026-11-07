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

    public function test_female_chest_volume_has_explicit_and_distinct_visual_instructions(): void
    {
        $builder = new PlayerCharacterGenerationPromptBuilder();
        $medium = $builder->build([
            'gender' => 'female',
            'appearance' => ['chest_volume' => 'medium'],
        ]);
        $full = $builder->build([
            'gender' => 'female',
            'appearance' => ['chest_volume' => 'full'],
        ]);
        $male = $builder->build([
            'gender' => 'male',
            'appearance' => ['chest_volume' => 'full'],
        ]);

        $this->assertStringContainsString('medium bust with a natural', $medium);
        $this->assertStringContainsString('pronounced fuller bust, visibly more projected', $full);
        $this->assertStringContainsString('jersey contour than medium', $full);
        $this->assertNotSame($medium, $full);
        $this->assertStringNotContainsString('pronounced fuller bust, visibly more projected', $male);
        $this->assertStringContainsString('"chest_volume": null', $male);
    }

    public function test_selected_headband_is_mandatory_and_on_forehead_not_an_elbow(): void
    {
        $builder = new PlayerCharacterGenerationPromptBuilder();
        $withHeadband = $builder->build([
            'appearance' => ['attributes' => ['headband', 'elbow_left']],
        ]);
        $withoutHeadband = $builder->build([
            'appearance' => ['attributes' => ['elbow_left']],
        ]);

        $this->assertStringContainsString('"headband"', $withHeadband);
        $this->assertStringContainsString('MANDATORY: a clearly visible athletic fabric sweatband', $withHeadband);
        $this->assertStringContainsString('across the forehead', $withHeadband);
        $this->assertStringContainsString('in front of the hair', $withHeadband);
        $this->assertStringContainsString('never on an elbow, upper arm or wrist', $withHeadband);
        $this->assertStringContainsString("PLAYER'S LEFT elbow", $withHeadband);
        $this->assertStringNotContainsString('MANDATORY: a clearly visible athletic fabric sweatband', $withoutHeadband);
    }

    public function test_uniform_selection_uses_own_palette_and_sleeves_without_inventing_team_branding(): void
    {
        $builder = new PlayerCharacterGenerationPromptBuilder();
        $saved = $builder->build([
            'gender' => 'female',
            'uniform' => [
                'source' => 'mine',
                'name' => 'Black and yellow',
                'primary_color' => '#111924',
                'secondary_color' => '#FFD100',
                'sleeve_style' => 'sleeved',
                'logo_source' => 'none',
            ],
        ]);
        $neutral = $builder->build([
            'uniform' => [
                'source' => 'neutral',
                'primary_color' => null,
                'secondary_color' => null,
                'sleeve_style' => 'sleeveless',
            ],
        ]);

        $this->assertStringContainsString('"source": "mine"', $saved);
        $this->assertStringContainsString('"primary_color": "#111924"', $saved);
        $this->assertStringContainsString('"secondary_color": "#FFD100"', $saved);
        $this->assertStringContainsString('"sleeve_style": "sleeved"', $saved);
        $this->assertStringContainsString('short athletic sleeves', $saved);
        $this->assertStringContainsString('saved design name is descriptive metadata only', $saved);
        $this->assertStringContainsString('"source": "neutral"', $neutral);
        $this->assertStringContainsString('neutral light slate/gray jersey', $neutral);
    }

    public function test_new_normal_body_type_is_described_in_ai_prompt(): void
    {
        $prompt = (new PlayerCharacterGenerationPromptBuilder())->build([
            'body_type' => 'normal',
        ]);

        $this->assertStringContainsString('normal build', $prompt);
    }


}
