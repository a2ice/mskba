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

    public function test_prompt_uses_selected_uniform_logo_instead_of_legacy_team_flag(): void
    {
        $builder = new PlayerCharacterGenerationPromptBuilder();

        $withLogo = $builder->build([
            'uniform' => ['source' => 'team', 'logo_source' => 'team'],
            'team' => ['name' => 'MSKBA Test', 'with_logo' => false],
        ]);
        $withoutLogo = $builder->build([
            'uniform' => ['source' => 'neutral', 'logo_source' => 'none'],
            'team' => ['name' => 'MSKBA Test', 'with_logo' => true],
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
        $this->assertStringContainsString('around the forehead', $withHeadband);
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

    public function test_saved_uniform_number_color_and_front_logo_position_are_authoritative(): void
    {
        $builder = new PlayerCharacterGenerationPromptBuilder();
        $prompt = $builder->build([
            'uniform' => [
                'source' => 'mine',
                'primary_color' => '#111924',
                'secondary_color' => null,
                'sleeve_style' => 'sleeveless',
                'logo_source' => 'custom',
                'jersey_number' => 7,
                'number_outline' => true,
                'logo_front_position' => 'right',
                'logo_back_position' => 'below',
            ],
            'team' => ['with_logo' => true],
        ]);

        $this->assertStringContainsString('"jersey_number": "07"', $prompt);
        $this->assertStringContainsString('jersey number "07"', $prompt);
        $this->assertStringContainsString('SMALL selected logo near the PLAYER\'S RIGHT side of the jersey', $prompt);
        $this->assertStringContainsString('"logo_back_position": "below"', $prompt);
        $this->assertStringContainsString('monochrome uniform', $prompt);
        $this->assertStringContainsString('black lettering with a white outline on dark fabric', $prompt);
    }

    public function test_only_enabled_front_and_shorts_logos_are_visible_on_front_avatar(): void
    {
        $builder = new PlayerCharacterGenerationPromptBuilder();

        $backOnly = $builder->build([
            'uniform' => [
                'source' => 'mine',
                'logo_source' => 'custom',
                'logo_front_enabled' => false,
                'logo_back_enabled' => true,
                'logo_shorts_enabled' => false,
            ],
        ]);
        $this->assertStringContainsString('Do not place a selected logo on the uniform', $backOnly);
        $this->assertStringNotContainsString('The LAST supplied image is the selected logo reference', $backOnly);

        $shortsOnly = $builder->build([
            'uniform' => [
                'source' => 'mine',
                'logo_source' => 'custom',
                'logo_front_enabled' => false,
                'logo_back_enabled' => true,
                'logo_shorts_enabled' => true,
                'logo_shorts_x' => 172,
                'logo_shorts_scale' => 0.7,
            ],
        ]);
        $this->assertStringContainsString('No logo anywhere on the front of the jersey', $shortsOnly);
        $this->assertStringContainsString("logo on the viewer's left leg panel", $shortsOnly);
        $this->assertStringContainsString('"logo_shorts_scale": 0.7', $shortsOnly);
    }

    public function test_disabled_uniform_number_never_appears_on_ai_avatar(): void
    {
        $prompt = (new PlayerCharacterGenerationPromptBuilder())->build([
            'uniform' => [
                'source' => 'mine',
                'jersey_number' => 23,
                'number_enabled' => false,
                'jersey_number_scale' => 0.65,
                'shorts_number_scale' => 1.15,
                'logo_source' => 'none',
            ],
        ]);

        $this->assertStringContainsString('"number_enabled": false', $prompt);
        $this->assertStringContainsString('jersey AND shorts must have no numerals anywhere', $prompt);
        $this->assertStringNotContainsString('Render exactly the jersey number "23"', $prompt);
    }


    public function test_team_design_uses_roster_number_including_zero_and_honors_no_number(): void
    {
        $builder = new PlayerCharacterGenerationPromptBuilder();
        $withRosterNumber = $builder->build([
            'uniform' => [
                'source' => 'team',
                'name' => 'Сборная МСКБА',
                'primary_color' => '#112233',
                'secondary_color' => '#FFC800',
                'logo_source' => 'none',
                'jersey_number' => 0,
                'number_enabled' => true,
                'number_color' => '#FFC800',
                'jersey_number_scale' => 1.2,
            ],
        ]);
        $withoutRosterNumber = $builder->build([
            'uniform' => [
                'source' => 'team',
                'primary_color' => '#112233',
                'logo_source' => 'none',
                'jersey_number' => null,
                'number_enabled' => false,
            ],
        ]);

        $this->assertStringContainsString('"jersey_number": "00"', $withRosterNumber);
        $this->assertStringContainsString('jersey number "00"', $withRosterNumber);
        $this->assertStringContainsString('"number_color": "#FFC800"', $withRosterNumber);
        $this->assertStringContainsString('No number is enabled', $withoutRosterNumber);
        $this->assertStringNotContainsString('Render exactly the jersey number', $withoutRosterNumber);
    }

    public function test_new_normal_body_type_is_described_in_ai_prompt(): void
    {
        $prompt = (new PlayerCharacterGenerationPromptBuilder())->build([
            'body_type' => 'normal',
        ]);

        $this->assertStringContainsString('normal build', $prompt);
    }


}
