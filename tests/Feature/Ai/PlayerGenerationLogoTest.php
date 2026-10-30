<?php

namespace Tests\Feature\Ai;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Media\Domain\Models\Media;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class PlayerGenerationLogoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_logos_are_private_normalized_webp_and_limited_to_three(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $index = route('account.player-character.logos.index');

        $this->actingAs($owner)->getJson($index)->assertOk()->assertJsonCount(0, 'logos');

        $created = [];
        for ($i = 1; $i <= 3; $i++) {
            $response = $this->actingAs($owner)->post($index, [
                'logo' => UploadedFile::fake()->image("club-{$i}.png", 1200, 700),
            ], ['Accept' => 'application/json']);
            $response->assertCreated();
            $created[] = (int) $response->json('logo.id');
        }

        $this->actingAs($owner)->getJson($index)->assertJsonCount(3, 'logos');
        $media = Media::query()->findOrFail($created[0]);
        $this->assertSame('image/webp', $media->mime);
        $this->assertLessThanOrEqual(500, max($media->width, $media->height));
        $this->assertSame('local', $media->disk);
        Storage::disk('local')->assertExists($media->path);

        $this->actingAs($other)->get(route('account.player-character.logos.show', $media))->assertNotFound();
        $this->actingAs($other)->deleteJson(route('account.player-character.logos.destroy', $media))->assertNotFound();
        $this->actingAs($owner)->get(route('account.player-character.logos.show', $media))
            ->assertOk()->assertHeader('Content-Type', 'image/webp');

        $this->actingAs($owner)->post($index, [
            'logo' => UploadedFile::fake()->image('fourth.png', 1200, 700),
        ], ['Accept' => 'application/json'])->assertStatus(422);
        $this->assertSame(3, Media::query()->where('collection', 'player_generation_logos')->count());

        $this->actingAs($owner)->deleteJson(route('account.player-character.logos.destroy', $media))->assertOk();
        Storage::disk('local')->assertMissing($media->path);
        $this->actingAs($owner)->getJson($index)->assertJsonCount(2, 'logos');
    }
}
