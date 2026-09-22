<?php

namespace Tests\Feature\Ai;

use App\Modules\Ai\Domain\Exceptions\AiServiceException;
use App\Modules\Ai\Infrastructure\Gateways\YandexPlayerCharacterAiGateway;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class YandexPlayerCharacterAiGatewayTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.yandex_ai', [
            'api_key' => 'AQVN-test-not-real',
            'folder_id' => 'b1g-test-folder',
            'base_url' => 'https://ai.api.cloud.yandex.test/v1',
            'face_validation_model' => 'qwen3.6-35b-a3b',
            'generation_model' => 'qwen3.6-35b-a3b',
            'image_model' => 'aliceai-image-art-3.0',
            'image_size' => '1024x1536',
            'image_quality' => 'high',
            'connect_timeout_seconds' => 1,
            'validation_timeout_seconds' => 5,
            'generation_timeout_seconds' => 30,
        ]);
    }

    public function test_it_validates_face_references_through_yandex_responses_api(): void
    {
        Http::fake([
            'https://ai.api.cloud.yandex.test/v1/responses' => Http::response([
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
                                    'slot' => 'right',
                                    'valid' => false,
                                    'detected_slot' => 'front',
                                    'reason' => 'Нужен профиль справа.',
                                    'skin_tone' => '#C8906E',
                                ],
                            ],
                        ], JSON_THROW_ON_ERROR),
                    ]],
                ]],
            ], 200, ['x-request-id' => 'yandex-face-test']),
        ]);

        $result = $this->gateway()->validateFaceReferences([
            'front' => ['contents' => 'front-image', 'mime' => 'image/webp'],
            'right' => ['contents' => 'right-image', 'mime' => 'image/webp'],
        ]);

        $this->assertTrue($result['front']->valid);
        $this->assertSame('front', $result['front']->detectedSlot);
        $this->assertSame('#C8906E', $result['front']->skinTone);
        $this->assertFalse($result['right']->valid);
        $this->assertSame('Нужен профиль справа.', $result['right']->reason);

        Http::assertSent(function (Request $request): bool {
            $payload = $request->data();

            return $request->url() === 'https://ai.api.cloud.yandex.test/v1/responses'
                && $request->header('Authorization')[0] === 'Api-Key AQVN-test-not-real'
                && $request->header('OpenAI-Project')[0] === 'b1g-test-folder'
                && data_get($payload, 'model') === 'gpt://b1g-test-folder/qwen3.6-35b-a3b'
                && data_get($payload, 'text.format.type') === 'json_schema'
                && data_get($payload, 'text.format.strict') === true
                && str_contains(
                    (string) data_get($payload, 'input.0.content.2.image_url'),
                    'data:image/webp;base64,',
                );
        });
    }

    public function test_it_generates_player_via_image_generation_tool_with_identity_references(): void
    {
        $png = $this->png(true);

        Http::fake([
            'https://ai.api.cloud.yandex.test/v1/responses' => Http::response([
                'output' => [[
                    'type' => 'image_generation_call',
                    'status' => 'completed',
                    'result' => base64_encode($png),
                ]],
            ], 200, ['x-request-id' => 'yandex-image-test']),
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
                'left' => ['contents' => 'left-image', 'mime' => 'image/webp'],
            ],
        ]);

        $this->assertSame('image/png', $result->mime);
        $this->assertSame($png, $result->contents);

        Http::assertSent(function (Request $request): bool {
            $payload = $request->data();

            return data_get($payload, 'model') === 'gpt://b1g-test-folder/qwen3.6-35b-a3b'
                && data_get($payload, 'tools.0.type') === 'image_generation'
                && data_get($payload, 'tools.0.model') === 'aliceai-image-art-3.0'
                && data_get($payload, 'tools.0.input_fidelity') === 'high'
                && data_get($payload, 'tools.0.action') === 'auto'
                && data_get($payload, 'tools.0.output_format') === 'png'
                && data_get($payload, 'tools.0.size') === '1024x1536'
                && str_contains(
                    (string) data_get($payload, 'input.0.content.2.image_url'),
                    'data:image/webp;base64,',
                );
        });
    }

    public function test_it_surfaces_yandex_opaque_background_as_experimental_capability_gap(): void
    {
        Http::fake([
            'https://ai.api.cloud.yandex.test/v1/responses' => Http::response([
                'output' => [[
                    'type' => 'image_generation_call',
                    'status' => 'completed',
                    'result' => base64_encode($this->png(false)),
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
            $this->assertSame('generation_background_not_transparent', $exception->errorCode);
            $this->assertStringContainsString('прозрачного фона', $exception->getMessage());
        }
    }

    public function test_it_maps_yandex_authentication_errors_without_leaking_provider_body(): void
    {
        Http::fake([
            'https://ai.api.cloud.yandex.test/v1/responses' => Http::response([
                'error' => [
                    'message' => 'secret provider message',
                    'type' => 'permission_denied',
                    'code' => 'PERMISSION_DENIED',
                ],
            ], 403, ['x-request-id' => 'yandex-auth-test']),
        ]);

        try {
            $this->gateway()->validateFaceReferences([
                'front' => ['contents' => 'front-image', 'mime' => 'image/webp'],
            ]);

            $this->fail('Expected authentication failure.');
        } catch (AiServiceException $exception) {
            $this->assertSame('ai_authentication_failed', $exception->errorCode);
            $this->assertSame(503, $exception->httpStatus);
            $this->assertSame('yandex-auth-test', $exception->context['provider_request_id']);
            $this->assertStringNotContainsString('secret provider message', $exception->getMessage());
        }
    }

    private function gateway(): YandexPlayerCharacterAiGateway
    {
        return app(YandexPlayerCharacterAiGateway::class);
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
