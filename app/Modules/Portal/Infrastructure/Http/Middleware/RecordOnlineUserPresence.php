<?php

namespace App\Modules\Portal\Infrastructure\Http\Middleware;

use App\Modules\Portal\Application\Services\OnlineUserPresence;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class RecordOnlineUserPresence
{
    public function __construct(private OnlineUserPresence $presence) {}

    public function handle(Request $request, Closure $next): Response
    {
        $userIdBefore = $request->user()?->getAuthIdentifier();

        if (is_numeric($userIdBefore)) {
            $this->presence->touch((int) $userIdBefore);
        }

        $response = $next($request);
        $userIdAfter = $request->user()?->getAuthIdentifier();

        if (is_numeric($userIdAfter)) {
            $this->presence->touch((int) $userIdAfter);
        } elseif (is_numeric($userIdBefore)) {
            $this->presence->forget((int) $userIdBefore);
        }

        return $response;
    }
}
