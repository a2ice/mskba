<?php

namespace App\Modules\Telegram\Infrastructure\Http\Middleware;

use Illuminate\Routing\Middleware\ThrottleRequests;

final class RouteScopedThrottleRequests extends ThrottleRequests
{
    /**
     * Keep the default Laravel throttle signature everywhere, but isolate the
     * high-frequency Telegram bot status polling from the bot-login start
     * endpoint. Numeric throttle middleware otherwise shares the same
     * user/IP signature across both routes.
     */
    protected function resolveRequestSignature($request)
    {
        $signature = parent::resolveRequestSignature($request);
        $routeName = $request->route()?->getName();

        if (in_array($routeName, [
            'auth.telegram.bot.start',
            'auth.telegram.bot.status',
        ], true)) {
            return $signature.'|'.$routeName;
        }

        return $signature;
    }
}
