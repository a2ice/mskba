<?php

namespace App\Modules\Ai\Infrastructure\Providers;

use App\Modules\Ai\Application\Contracts\PlayerCharacterAiGateway;
use App\Modules\Ai\Infrastructure\Gateways\NullPlayerCharacterAiGateway;
use Illuminate\Support\ServiceProvider;

final class AiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(
            PlayerCharacterAiGateway::class,
            NullPlayerCharacterAiGateway::class,
        );
    }
}
