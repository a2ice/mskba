<?php

use App\Modules\Event\Infrastructure\Http\Middleware\EnsureGameRosterContainsPlayers;
use App\Modules\Identity\Infrastructure\Http\Middleware\RecordBrowserFingerprint;
use App\Modules\Portal\Infrastructure\Http\Middleware\RecordOnlineUserPresence;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: [
            __DIR__.'/../routes/web.php',
            __DIR__.'/../routes/game-live.php',
        ],
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: '*');
        $middleware->appendToGroup('web', [
            RecordBrowserFingerprint::class,
            RecordOnlineUserPresence::class,
            EnsureGameRosterContainsPlayers::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
