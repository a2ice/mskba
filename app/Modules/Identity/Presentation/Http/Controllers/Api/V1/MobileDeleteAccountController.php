<?php

namespace App\Modules\Identity\Presentation\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Application\UseCases\DeleteOwnAccountHandler;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class MobileDeleteAccountController extends Controller
{
    public function __invoke(Request $request, DeleteOwnAccountHandler $deleteAccount): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        // No arbitrary user ID; identical canonical soft delete as the website.
        $deleteAccount->handle($user);

        return response()->json([
            'deleted' => true,
            'message' => 'Аккаунт удалён.',
        ]);
    }
}
