<?php

namespace Tests;

use App\Modules\Event\Domain\Enums\GameStatisticsStatusEnum;
use App\Modules\Event\Domain\Models\Event;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Testing\TestResponse;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Rate limiting is covered by the framework; sharing one test IP must not
        // make unrelated feature tests influence each other.
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    /**
     * Existing workflow tests predate explicit actual game start/end actions.
     * Keep their business assertions intact while moving only those legacy
     * requests into the lifecycle phase required by the production middleware.
     * New lifecycle tests do not use this compatibility adapter.
     *
     * @param array<string, mixed> $parameters
     * @param array<string, string> $cookies
     * @param array<string, mixed> $files
     * @param array<string, string> $server
     */
    public function call(
        $method,
        $uri,
        $parameters = [],
        $cookies = [],
        $files = [],
        $server = [],
        $content = null,
    ): TestResponse {
        $this->prepareLegacyGameLifecycle((string) $uri);

        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }

    private function prepareLegacyGameLifecycle(string $uri): void
    {
        if (static::class !== \Tests\Feature\Event\GameAndTeamWorkflowTest::class) {
            return;
        }

        $path = (string) parse_url($uri, PHP_URL_PATH);
        if (! preg_match('#^/events/([^/]+)/game/(statistics|score)(?:/(complete|confirm))?$#', $path, $matches)) {
            return;
        }

        $identifier = rawurldecode($matches[1]);
        $finalAction = $matches[3] ?? null;
        $game = Event::query()->whereRouteIdentifier($identifier)->first();
        if ($game === null) {
            return;
        }

        if ($game->actual_started_at === null) {
            $game->forceFill(['actual_started_at' => now()->subMinute()])->save();
            $detail = $game->gameDetail()->first();
            if ($detail?->statistics_status === GameStatisticsStatusEnum::NOT_STARTED) {
                $detail->update(['statistics_status' => GameStatisticsStatusEnum::ENTERING]);
            }
        }

        if ($finalAction !== null && $game->actual_ended_at === null) {
            $game->forceFill(['actual_ended_at' => now()])->save();
            $detail = $game->gameDetail()->first();
            if ($detail?->statistics_status === GameStatisticsStatusEnum::ENTERING) {
                $detail->update(['statistics_status' => GameStatisticsStatusEnum::READY]);
            }
        }
    }
}
