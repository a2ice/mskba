<?php

namespace Tests\Feature\Identity;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class PlayerCharacterModelAssetTest extends TestCase
{
    #[Test]
    public function clean_authored_model_keeps_the_geometry_required_by_the_renderer(): void
    {
        $path = resource_path('themes/mskba_dark/models/player-character/mskba-male-base-test-clean.glb');
        $binary = file_get_contents($path);

        $this->assertIsString($binary);
        $this->assertGreaterThan(500_000, strlen($binary));

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
        $primitive = $document['meshes'][0]['primitives'][0];
        $positionAccessor = $document['accessors'][$primitive['attributes']['POSITION']];
        $indexAccessor = $document['accessors'][$primitive['indices']];

        $this->assertArrayHasKey('NORMAL', $primitive['attributes']);
        $this->assertArrayHasKey('TEXCOORD_0', $primitive['attributes']);
        $this->assertGreaterThanOrEqual(10_000, $positionAccessor['count']);
        $this->assertGreaterThanOrEqual(60_000, $indexAccessor['count']);
        $this->assertGreaterThan(1.7, $positionAccessor['max'][1] - $positionAccessor['min'][1]);
        $this->assertLessThan(2.1, $positionAccessor['max'][1] - $positionAccessor['min'][1]);
    }

    #[Test]
    public function authored_renderer_loads_local_three_and_the_glb_as_a_vite_asset(): void
    {
        $source = file_get_contents(resource_path('themes/mskba_dark/js/features/player-character-authored-renderer.js'));

        $this->assertIsString($source);
        $this->assertStringContainsString('mskba-male-base-test-clean.glb?url', $source);
        $this->assertStringContainsString("import('three')", $source);
        $this->assertStringContainsString('loadAsync(authoredMaleModelUrl)', $source);
        $this->assertStringNotContainsString('esm.sh', $source);
        $this->assertStringNotContainsString('DecompressionStream', $source);
        $this->assertStringNotContainsString('modelPart', $source);
    }
}
