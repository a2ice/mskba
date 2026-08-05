<?php

namespace App\Modules\Coordination\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Coordination\Application\Services\CoordinationAccess;
use App\Modules\Coordination\Domain\Models\CoordinationSession;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class CoordinationManagementController extends Controller
{
    public function __invoke(
        Request $request,
        CoordinationSession $coordination,
        CurrentActorResolver $actors,
        CoordinationAccess $access,
    ): Response {
        $actor = $actors->resolveForRequest($request);
        abort_if($actor === null || ! $access->canManage($coordination, $actor), 403);

        return app()->call([app(CoordinationController::class), 'show'], [
            'request' => $request,
            'coordination' => $coordination,
        ]);
    }
}
