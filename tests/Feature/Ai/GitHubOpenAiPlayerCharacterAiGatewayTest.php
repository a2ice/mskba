<?php

namespace Tests\Feature\Ai;

use App\Modules\Ai\Infrastructure\Gateways\GitHubOpenAiPlayerCharacterAiGateway;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class GitHubOpenAiPlayerCharacterAiGatewayTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.github_openai', [
            'api_url' => 'https://api.github.test',
            'repository' => 'a2ice/mskba',
            'workflow' => 'openai-player-generate.yml',
            'ref' => 'main',
            'token' => 'github-test-token',
            'callback_secret' => 'callback-test-secret',
            'manifest_ttl_minutes' => 20,
            'connect_timeout_seconds' => 1,
            'dispatch_timeout_seconds' => 5,
        ]);
    }

    public function test_it_dispatches_generation_to_github_actions_without_sending_images(): void
    {
        Http::fake([
            'https://api.github.test/repos/a2ice/mskba/actions/workflows/openai-player-generate.yml/dispatches' => Http::response(null, 204, ['x-github-request-id' => 'github-request-test']),
        ]);

        $result = app(GitHubOpenAiPlayerCharacterAiGateway::class)->generatePlayerCharacter([
            'generation_id' => '018f3a5e-5f20-7d93-a278-000000000001',
            'face_references' => [
                'front' => ['contents' => 'private-image-bytes', 'mime' => 'image/webp'],
            ],
        ]);

        $this->assertTrue($result->isPending());
        $this->assertSame('018f3a5e-5f20-7d93-a278-000000000001', $result->generationId);

        Http::assertSent(function (Request $request): bool {
            $payload = $request->data();
            $serialized = json_encode($payload);

            return $request->url() === 'https://api.github.test/repos/a2ice/mskba/actions/workflows/openai-player-generate.yml/dispatches'
                && $request->hasHeader('Authorization', 'Bearer github-test-token')
                && data_get($payload, 'ref') === 'main'
                && data_get($payload, 'inputs.generation_id') === '018f3a5e-5f20-7d93-a278-000000000001'
                && str_contains((string) data_get($payload, 'inputs.manifest_url'), '/api/integrations/github-openai/')
                && ! str_contains((string) $serialized, 'private-image-bytes');
        });
    }

    public function test_it_performs_only_structural_face_validation_on_vds(): void
    {
        $image = imagecreatetruecolor(256, 256);
        ob_start();
        imagewebp($image);
        $contents = ob_get_clean();
        imagedestroy($image);

        $this->assertIsString($contents);

        $results = app(GitHubOpenAiPlayerCharacterAiGateway::class)->validateFaceReferences([
            'front' => ['contents' => $contents, 'mime' => 'image/webp'],
            'left' => ['contents' => 'not-an-image', 'mime' => 'image/webp'],
        ]);

        $this->assertTrue($results['front']->valid);
        $this->assertFalse($results['left']->valid);
    }
}
