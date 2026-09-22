<?php

namespace Tests\Feature\Ai;

use App\Modules\Ai\Domain\Exceptions\AiServiceException;
use App\Modules\Ai\Infrastructure\Gateways\OpenAiPlayerCharacterAiGateway;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class OpenAiPlayerCharacterAiGatewayTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.openai', [
            'api_key' => 'sk-test-not-real',
            'base_url' => 'https://api.openai.test/v1',
            'face_validation_model' => 'gpt-5.6-luna',
            'image_model' => 'gpt-image-2.5-sunburst',
            'image_size' => '1024x1536',
            'image_quality' => 'medium',
            'connect_timeout_seconds' => 1,
            'validation_timeout_seconds' => 5,
            'generation_timeout_seconds' => 30,
        ]);
    }

    public function test_it_validates_face_references_with_structured_openai_response(): void
    {
        Http::fake([
            'https://api.openai.test/v1/responses' => Http::response([
                'output' => [[
                    'type' => 'message',
                    'content' => [[
                        'type' => 'output_text',
                        'text' => json_encode([
                            'references' => [
                                [
                                    'slot' => 'front',
                                    'valid' => true,
                                    'detected_slot' => 'front',
                                    'reason' => '',
                                    'skin_tone' => '#C8906E',
                                ],
                                [
                                    'slot' => 'left',
                                    'valid' => false,
                                    'detected_slot' => 'front',
                                    'reason' => 'Нужен профиль слева.',
                                    'skin_tone' => '#C8906E',
                                ],
                            ],
                        ], JSON_THROW_ON_ERROR),
                    ]],
                ]],
            ], 200, ['x-request-id' => 'req_face_test']),
        ]);

        $result = $this->gateway()->validateFaceReferences([
            'front' => ['contents' => 'front-image', 'mime' => 'image/webp'],
            'left' => ['contents' => 'left-image', 'mime' => 'image/webp'],
        ]);

        $this->assertTrue($result['front']->valid);
        $this->assertSame('front', $result['front']->detectedSlot);
        $this->assertSame('#C8906E', $result['front']->skinTone);

        $this->assertFalse($result['left']->valid);
        $this->assertSame('front', $result['left']->detectedSlot);
        $this->assertSame('Нужен профиль слева.', $result['left']->reason);

        Http::assertSent(function (Request $request): bool {
            $payload = $request->data();

            return $request->url() === 'https://api.openai.test/v1/responses'
                && data_get($payload, 'model') === 'gpt-5.6-luna'
                && data_get($payload, 'text.format.type') === 'json_schema'
                && data_get($payload, 'text.format.strict') === true
                && str_contains(
                    (string) data_get($payload, 'input.0.content.2.image_url'),
                    'data:image/webp;base64,',
                );
        });
    }

    public function test_it_generates_png_with_transparent_background_from_face_references(): void
    {
        $png = $this->png(true);

        Http::fake([
            'https://api.openai.test/v1/images/edits' => Http::response([
                'data' => [[
                    'b64_json' => base64_encode($png),
                ]],
            ], 200, ['x-request-id' => 'req_image_test']),
        ]);

        $result = $this->gateway()->generatePlayerCharacter([
            'gender' => 'male',
            'height_cm' => 195,
            'weight_kg' => 90,
            'body_type' => 'athletic',
            'appearance' => [
                'shoes' => 'black',
                'attributes' => ['headband'],
            ],
            'team' => [
                'name' => 'Test Team',
                'colors' => [
                    'home_primary' => '#FF6600',
                    'home_secondary' => '#111111',
                ],
            ],
            'face_references' => [
                'front' => ['contents' => 'front-image', 'mime' => 'image/webp'],
                'right' => ['contents' => 'right-image', 'mime' => 'image/webp'],
            ],
        ]);

        $this->assertSame('image/png', $result->mime);
        $this->assertSame($png, $result->contents);

        Http::assertSent(function (Request $request): bool {
            $data = $request->data();

            return $request->url() === 'https://api.openai.test/v1/images/edits'
                && ($data['model'] ?? null) === 'gpt-image-2.5-sunburst'
                && ($data['background'] ?? null) === 'transparent'
                && ($data['output_format'] ?? null) === 'png'
                && ($data['size'] ?? null) === '1024x1536'
                && ($data['quality'] ?? null) === 'medium';
        });
    }

    public function test_it_rejects_generated_image_without_transparent_background(): void
    {
        Http::fake([
            'https://api.openai.test/v1/images/edits' => Http::response([
                'data' => [[
                    'b64_json' => base64_encode($this->png(false)),
                ]],
            ]),
        ]);

        try {
            $this->gateway()->generatePlayerCharacter([
                'face_references' => [
                    'front' => ['contents' => 'front-image', 'mime' => 'image/webp'],
                ],
            ]);

            $this->fail('Expected generation failure for opaque image.');
        } catch (AiServiceException $exception) {
            $this->assertSame('generation_failed', $exception->errorCode);
            $this->assertStringContainsString('прозрачным фоном', $exception->getMessage());
        }
    }

    public function test_it_maps_openai_authentication_errors_without_leaking_provider_body(): void
    {
        Http::fake([
            'https://api.openai.test/v1/responses' => Http::response([
                'error' => [
                    'message' => 'secret provider message',
                    'type' => 'invalid_request_error',
                    'code' => 'invalid_api_key',
                ],
            ], 401, ['x-request-id' => 'req_auth_test']),
        ]);

        try {
            $this->gateway()->validateFaceReferences([
                'front' => ['contents' => 'front-image', 'mime' => 'image/webp'],
            ]);

            $this->fail('Expected authentication failure.');
        } catch (AiServiceException $exception) {
            $this->assertSame('ai_authentication_failed', $exception->errorCode);
            $this->assertSame(503, $exception->httpStatus);
            $this->assertSame('req_auth_test', $exception->context['provider_request_id']);
            $this->assertStringNotContainsString('secret provider message', $exception->getMessage());
        }
    }

    private function gateway(): OpenAiPlayerCharacterAiGateway
    {
        return app(OpenAiPlayerCharacterAiGateway::class);
    }

    private function png(bool $transparent): string
    {
        $image = imagecreatetruecolor(32, 48);
        imagealphablending($image, false);
        imagesavealpha($image, true);

        $color = $transparent
            ? imagecolorallocatealpha($image, 0, 0, 0, 127)
            : imagecolorallocatealpha($image, 255, 255, 255, 0);

        imagefill($image, 0, 0, $color);

        ob_start();
        imagepng($image);
        $contents = ob_get_clean();
        imagedestroy($image);

        $this->assertIsString($contents);

        return $contents;
    }
}
