<?php

namespace App\Modules\Acquisition\Presentation\Http\Middleware;

use App\Modules\Acquisition\Application\Services\AcquisitionTracker;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class LinkAcquisitionVisitToAuthenticatedUser
{
    public function __construct(private AcquisitionTracker $tracker) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($request->user() !== null) {
            $this->tracker->attachCurrentUser($request);
        }

        return $response;
    }
}
