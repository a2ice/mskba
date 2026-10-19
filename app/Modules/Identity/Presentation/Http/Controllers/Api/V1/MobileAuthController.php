<?php

namespace App\Modules\Identity\Presentation\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Application\Services\CredentialAuthenticationService;
use App\Modules\Identity\Presentation\Http\Resources\Api\V1\OwnUserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Laravel\Sanctum\PersonalAccessToken;

final class MobileAuthController extends Controller
{
    public function login(Request $request, CredentialAuthenticationService $credentials): JsonResponse
    {
        $input = $request->validate([
            'login' => ['required', 'string', 'min:3', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
            'device_name' => ['sometimes', 'required', 'string', 'min:1', 'max:80'],
        ]);

        $result = $credentials->verify($input['login'], $input['password']);
        if (! $result->successful()) {
            return response()->json([
                'message' => $result->message,
            ], $result->httpStatus);
        }

        $user = $result->user;
        $name = 'mskba-client:'.($input['device_name'] ?? 'mobile');
        $token = $user->createToken(
            $name,
            ['profile:read', 'auth:logout'],
            now()->addDays(30),
        );
        $credentials->recordFirstLogin($user);

        return response()->json([
            'token_type' => 'Bearer',
            'access_token' => $token->plainTextToken,
            'expires_at' => $token->accessToken->expires_at?->toIso8601String(),
            'requires_password_change' => $result->temporaryPassword,
            'user' => (new OwnUserResource($user->loadMissing('profile.activeAvatar', 'participationRoles')))->toArray($request),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'data' => (new OwnUserResource(
                $request->user()->loadMissing('profile.activeAvatar', 'participationRoles'),
            ))->toArray($request),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        /** @var PersonalAccessToken $token */
        $token = $request->user()->currentAccessToken();
        $token->delete();

        return response()->json(['message' => 'Вы вышли из системы на этом устройстве.']);
    }
}
