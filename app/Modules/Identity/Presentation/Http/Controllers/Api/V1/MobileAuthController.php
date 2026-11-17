<?php

namespace App\Modules\Identity\Presentation\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use App\Modules\Identity\Application\DTO\PrivacyConsentDTO;
use App\Modules\Identity\Application\Services\CredentialAuthenticationService;
use App\Modules\Identity\Application\UseCases\RegisterUserHandler;
use App\Modules\Identity\Domain\Enums\UserRegistrationChannelEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Identity\Presentation\Http\Requests\Api\V1\MobileRegisterRequest;
use App\Modules\Identity\Presentation\Http\Resources\Api\V1\OwnUserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

final class MobileAuthController extends Controller
{
    public function register(
        MobileRegisterRequest $request,
        RegisterUserHandler $registerUser,
        CredentialAuthenticationService $credentials,
    ): JsonResponse {
        $validated = $request->validated();

        // Reuse the website's atomic registration use case and consent audit.
        // No browser session or CSRF cookie is created for native clients.
        $user = $registerUser->handle(
            username: $validated['username'],
            password: $validated['password'],
            participantRole: $request->participantRole(),
            profile: $request->profile(),
            playerData: $request->playerData(),
            privacyConsent: new PrivacyConsentDTO(
                documentVersion: (string) config('legal.personal_data_consent_version'),
                acceptedAt: CarbonImmutable::now(),
                source: 'mobile_api_registration',
                ipAddress: $request->ip(),
                userAgent: $request->userAgent(),
            ),
            registrationChannel: UserRegistrationChannelEnum::MOBILE_APP,
        );

        return $this->tokenResponse(
            $request,
            $user,
            $validated['device_name'] ?? 'mobile',
            false,
            $credentials,
            201,
        );
    }

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

        return $this->tokenResponse(
            $request,
            $result->user,
            $input['device_name'] ?? 'mobile',
            $result->temporaryPassword,
            $credentials,
        );
    }

    private function tokenResponse(
        Request $request,
        User $user,
        string $deviceName,
        bool $requiresPasswordChange,
        CredentialAuthenticationService $credentials,
        int $httpStatus = 200,
    ): JsonResponse {
        $token = $user->createToken(
            'mskba-client:'.$deviceName,
            ['profile:read', 'auth:logout'],
            now()->addDays(30),
        );
        $credentials->recordFirstLogin($user);

        return response()->json([
            'token_type' => 'Bearer',
            'access_token' => $token->plainTextToken,
            'expires_at' => $token->accessToken->expires_at?->toIso8601String(),
            'requires_password_change' => $requiresPasswordChange,
            'user' => (new OwnUserResource($user->loadMissing('profile.activeAvatar', 'participationRoles')))->toArray($request),
        ], $httpStatus);
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
