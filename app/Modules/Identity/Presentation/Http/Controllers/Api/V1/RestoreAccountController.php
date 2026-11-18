<?php

namespace App\Modules\Identity\Presentation\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Application\UseCases\RestoreOwnAccountHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class RestoreAccountController extends Controller
{
    public function requestCode(Request $request, RestoreOwnAccountHandler $restoration): JsonResponse
    {
        $validated = $request->validate([
            'contact' => ['required', 'email', 'max:254'],
        ]);

        $restoration->requestCode(mb_strtolower(trim($validated['contact'])));

        // Never reveal whether the address belongs to a deleted account.
        return response()->json([
            'message' => 'Если аккаунт доступен для восстановления, код отправлен на подтверждённый контакт.',
        ]);
    }

    public function confirm(Request $request, RestoreOwnAccountHandler $restoration): JsonResponse
    {
        $validated = $request->validate([
            'contact' => ['required', 'email', 'max:254'],
            'code' => ['required', 'string', 'regex:/^[0-9]{6}$/D'],
        ]);

        $restored = $restoration->confirm(
            mb_strtolower(trim($validated['contact'])),
            $validated['code'],
        );

        if (! $restored) {
            return response()->json([
                'message' => 'Не удалось подтвердить восстановление. Проверьте код или запросите новый.',
            ], 422);
        }

        return response()->json([
            'restored' => true,
            'message' => 'Аккаунт восстановлен. Теперь войдите заново.',
        ]);
    }
}
