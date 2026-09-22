<?php

namespace App\Modules\Ai\Infrastructure\Providers;

use App\Modules\Ai\Application\Contracts\PlayerCharacterAiGateway;
use App\Modules\Ai\Infrastructure\Gateways\NullPlayerCharacterAiGateway;
use App\Modules\Ai\Infrastructure\Gateways\OpenAiPlayerCharacterAiGateway;
use App\Modules\Ai\Infrastructure\Gateways\YandexPlayerCharacterAiGateway;
use Illuminate\Support\ServiceProvider;

final class AiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(
            PlayerCharacterAiGateway::class,
            static function ($app): PlayerCharacterAiGateway {
                $provider = mb_strtolower(trim((string) $app['config']->get(
                    'services.player_character_ai.provider',
                    'auto',
                )));

                $yandexConfigured =
                    trim((string) $app['config']->get('services.yandex_ai.api_key')) !== ''
                    && trim((string) $app['config']->get('services.yandex_ai.folder_id')) !== '';
                $openAiConfigured =
                    trim((string) $app['config']->get('services.openai.api_key')) !== '';

                return match ($provider) {
                    'yandex' => $yandexConfigured
                        ? $app->make(YandexPlayerCharacterAiGateway::class)
                        : $app->make(NullPlayerCharacterAiGateway::class),
                    'openai' => $openAiConfigured
                        ? $app->make(OpenAiPlayerCharacterAiGateway::class)
                        : $app->make(NullPlayerCharacterAiGateway::class),
                    'null', 'none', 'disabled' => $app->make(NullPlayerCharacterAiGateway::class),
                    default => $yandexConfigured
                        ? $app->make(YandexPlayerCharacterAiGateway::class)
                        : ($openAiConfigured
                            ? $app->make(OpenAiPlayerCharacterAiGateway::class)
                            : $app->make(NullPlayerCharacterAiGateway::class)),
                };
            },
        );
    }
}
