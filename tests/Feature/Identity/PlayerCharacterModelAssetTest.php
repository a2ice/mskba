<?php

namespace Tests\Feature\Identity;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class PlayerCharacterModelAssetTest extends TestCase
{
    #[Test]
    public function male_body_preview_exposes_the_height_and_bmi_morph_lattice(): void
    {
        [, $document] = $this->readGlb(
            resource_path('themes/mskba_dark/models/player-character/mskba-male-player-body-posed-preview.glb'),
        );
        $meshNames = array_column($document['meshes'], 'name');
        $bodyIndex = array_search('Body_Mesh', $meshNames, true);

        $this->assertNotFalse($bodyIndex);
        $targetNames = $document['meshes'][$bodyIndex]['extras']['targetNames'];

        foreach ([
            'metric_h150_bmi17',
            'metric_h150_bmi23',
            'metric_h150_bmi38',
            'metric_h185_bmi17',
            'metric_h185_bmi38',
            'metric_h220_bmi17',
            'metric_h220_bmi23',
            'metric_h220_bmi38',
            'body_athletic',
            'body_muscle',
        ] as $targetName) {
            $this->assertContains($targetName, $targetNames);
        }
    }

    #[Test]
    public function male_and_female_assets_implement_the_same_authored_runtime_contract(): void
    {
        $documents = [];

        foreach (['male' => 'Male', 'female' => 'Female'] as $gender => $title) {
            [$binary, $document] = $this->readGlb(
                resource_path("themes/mskba_dark/models/player-character/mskba-{$gender}-player-v1.glb"),
            );
            $documents[$gender] = $binary;
            $meshNames = array_column($document['meshes'], 'name');
            $bodyIndex = array_search("MSKBA_{$title}_Body_Mesh", $meshNames, true);

            $this->assertNotFalse($bodyIndex);
            $body = $document['meshes'][$bodyIndex];
            $primitive = $body['primitives'][0];
            $positionAccessor = $document['accessors'][$primitive['attributes']['POSITION']];
            $indexAccessor = $document['accessors'][$primitive['indices']];

            $this->assertSame(
                ['body_slim', 'body_heavy', 'body_muscular', 'body_stocky'],
                $body['extras']['targetNames'],
            );
            $this->assertCount(4, $primitive['targets']);
            $this->assertArrayHasKey('NORMAL', $primitive['attributes']);
            $this->assertArrayHasKey('TEXCOORD_0', $primitive['attributes']);
            $this->assertGreaterThanOrEqual(10_000, $positionAccessor['count']);
            $this->assertGreaterThanOrEqual(60_000, $indexAccessor['count']);
            $this->assertGreaterThan(1.5, $positionAccessor['max'][1] - $positionAccessor['min'][1]);
            $this->assertLessThan(2.1, $positionAccessor['max'][1] - $positionAccessor['min'][1]);
            $this->assertContains("MSKBA_Player_{$title}", array_column($document['nodes'], 'name'));
            $this->assertContains('Body', array_column($document['nodes'], 'name'));
            $this->assertContains('Head', array_column($document['nodes'], 'name'));
            $this->assertContains('MSKBA_Skin', array_column($document['materials'], 'name'));
            $this->assertContains(
                $gender === 'male' ? 'MSKBA_Hair_Male_Fade' : 'MSKBA_Hair_Female_Ponytail',
                array_column($document['nodes'], 'name'),
            );
        }

        $this->assertNotSame($documents['male'], $documents['female']);
    }

    #[Test]
    public function authored_renderer_loads_local_three_and_the_glb_as_a_vite_asset(): void
    {
        $source = file_get_contents(resource_path('themes/mskba_dark/js/features/player-character-authored-renderer.js'));

        $this->assertIsString($source);
        $this->assertStringContainsString('mskba-male-player-body-posed-preview.glb?url', $source);
        $this->assertStringContainsString('mskba-female-player-v1.glb?url', $source);
        $this->assertStringContainsString("import('three')", $source);
        $this->assertStringContainsString('MODEL_URLS[normalizeGender(gender)]', $source);
        $this->assertStringNotContainsString('esm.sh', $source);
        $this->assertStringNotContainsString('DecompressionStream', $source);
        $this->assertStringNotContainsString('modelPart', $source);
    }

    #[Test]
    public function authored_character_customization_is_wired_for_body_shape_hair_and_facial_hair(): void
    {
        $stage = file_get_contents(resource_path('themes/mskba_dark/js/features/player-character-stage.js'));
        $customization = file_get_contents(resource_path('themes/mskba_dark/js/features/player-character-authored-customization.js'));
        $tooltips = file_get_contents(resource_path('themes/mskba_dark/js/features/tooltips.js'));

        $this->assertIsString($stage);
        $this->assertIsString($customization);
        $this->assertIsString($tooltips);

        $this->assertStringContainsString('applyAuthoredBodyShape', $stage);
        $this->assertStringContainsString('updateAuthoredAccessories', $stage);

        $this->assertStringContainsString('BODY_TYPE_MORPHS', $customization);
        $this->assertStringContainsString("'metric_h150_bmi38'", $customization);
        $this->assertStringContainsString("'metric_h220_bmi38'", $customization);
        $this->assertStringContainsString('BMI_NODES', $customization);
        $this->assertStringContainsString('MSKBA_Hair_Male_Fade', $customization);
        $this->assertStringContainsString('MSKBA_Hair_Female_Ponytail', $customization);
        $this->assertStringContainsString('MSKBA_Beard_Short', $customization);
        $this->assertStringContainsString("hairColor", $customization);
        $this->assertStringContainsString("facialHair", $customization);
        $this->assertStringNotContainsString('SphereGeometry', $customization);
        $this->assertStringNotContainsString('collectHeadMetrics', $customization);

        $this->assertStringContainsString(
            ".account-player-character-configurator__swatch",
            $tooltips,
        );
    }

    /**
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function readGlb(string $path): array
    {
        $binary = file_get_contents($path);

        $this->assertIsString($binary);
        $this->assertGreaterThan(1_000_000, strlen($binary));

        $header = unpack('a4magic/Vversion/Vlength', substr($binary, 0, 12));
        $this->assertSame('glTF', $header['magic']);
        $this->assertSame(2, $header['version']);
        $this->assertSame(strlen($binary), $header['length']);

        $jsonChunk = unpack('Vlength/Vtype', substr($binary, 12, 8));
        $this->assertSame(0x4E4F534A, $jsonChunk['type']);
        $document = json_decode(
            rtrim(substr($binary, 20, $jsonChunk['length']), "\0 \t\n\r"),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        return [$binary, $document];
    }
}
