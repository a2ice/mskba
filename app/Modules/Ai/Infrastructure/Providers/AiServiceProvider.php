<?php

namespace App\Modules\Ai\Infrastructure\Providers;

use App\Modules\Ai\Application\Contracts\PlayerCharacterAiGateway;
use App\Modules\Ai\Infrastructure\Gateways\NullPlayerCharacterAiGateway;
use App\Modules\Ai\Infrastructure\Gateways\OpenAiPlayerCharacterAiGateway;
use Illuminate\Support\ServiceProvider;

final class AiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(
            PlayerCharacterAiGateway::class,
            static fn ($app) => trim((string) $app['config']->get('services.openai.api_key')) !== ''
                ? $app->make(OpenAiPlayerCharacterAiGateway::class)
                : $app->make(NullPlayerCharacterAiGateway::class),
        );
    }
}
