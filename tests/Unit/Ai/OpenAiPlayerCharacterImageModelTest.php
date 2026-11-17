<?php

namespace Tests\Unit\Ai;

use App\Modules\Ai\Infrastructure\Support\OpenAiPlayerCharacterImageModel;
use Tests\TestCase;

final class OpenAiPlayerCharacterImageModelTest extends TestCase
{
    public function test_legacy_models_are_migrated_to_transparency_capable_default(): void
    {
        foreach (['gpt-image-1', 'gpt-image-2', 'gpt-image-2-2026-04-21', ''] as $legacy) {
            config()->set('services.openai.image_model', $legacy);

            $this->assertSame('gpt-image-2.5-sunburst', OpenAiPlayerCharacterImageModel::configured());
        }
    }

    public function test_explicitly_supported_model_is_preserved(): void
    {
        config()->set('services.openai.image_model', 'gpt-image-2.5-flare');

        $this->assertSame('gpt-image-2.5-flare', OpenAiPlayerCharacterImageModel::configured());
    }
}
