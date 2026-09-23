<?php

namespace Tests\Feature\Ai;

use App\Modules\Ai\Application\Contracts\PlayerCharacterAiGateway;
use App\Modules\Ai\Infrastructure\Gateways\GitHubOpenAiPlayerCharacterAiGateway;
use App\Modules\Ai\Infrastructure\Gateways\NullPlayerCharacterAiGateway;
use App\Modules\Ai\Infrastructure\Gateways\OpenAiPlayerCharacterAiGateway;
use App\Modules\Ai\Infrastructure\Gateways\YandexPlayerCharacterAiGateway;
use Tests\TestCase;

final class AiServiceProviderTest extends TestCase
{
    public function test_auto_prefers_github_openai_when_dispatch_credentials_are_configured(): void
    {
        config()->set('services.player_character_ai.provider', 'auto');
        config()->set('services.github_openai.token', 'github-test-token');
        config()->set('services.github_openai.callback_secret', 'callback-test-secret');
        config()->set('services.github_openai.repository', 'a2ice/mskba');
        config()->set('services.yandex_ai.api_key', 'yandex-test-key');
        config()->set('services.yandex_ai.folder_id', 'folder-test');

        $this->app->forgetInstance(PlayerCharacterAiGateway::class);

        $this->assertInstanceOf(
            GitHubOpenAiPlayerCharacterAiGateway::class,
            app(PlayerCharacterAiGateway::class),
        );
    }

    public function test_auto_prefers_yandex_when_both_yandex_credentials_are_configured(): void
    {
        config()->set('services.player_character_ai.provider', 'auto');
        config()->set('services.yandex_ai.api_key', 'yandex-test-key');
        config()->set('services.yandex_ai.folder_id', 'folder-test');
        config()->set('services.openai.api_key', 'openai-test-key');

        $this->app->forgetInstance(PlayerCharacterAiGateway::class);

        $this->assertInstanceOf(
            YandexPlayerCharacterAiGateway::class,
            app(PlayerCharacterAiGateway::class),
        );
    }

    public function test_explicit_openai_provider_still_remains_available(): void
    {
        config()->set('services.player_character_ai.provider', 'openai');
        config()->set('services.yandex_ai.api_key', 'yandex-test-key');
        config()->set('services.yandex_ai.folder_id', 'folder-test');
        config()->set('services.openai.api_key', 'openai-test-key');

        $this->app->forgetInstance(PlayerCharacterAiGateway::class);

        $this->assertInstanceOf(
            OpenAiPlayerCharacterAiGateway::class,
            app(PlayerCharacterAiGateway::class),
        );
    }

    public function test_yandex_without_complete_credentials_falls_back_to_null_provider(): void
    {
        config()->set('services.player_character_ai.provider', 'yandex');
        config()->set('services.yandex_ai.api_key', 'yandex-test-key');
        config()->set('services.yandex_ai.folder_id', '');
        config()->set('services.openai.api_key', 'openai-test-key');

        $this->app->forgetInstance(PlayerCharacterAiGateway::class);

        $this->assertInstanceOf(
            NullPlayerCharacterAiGateway::class,
            app(PlayerCharacterAiGateway::class),
        );
    }
}
