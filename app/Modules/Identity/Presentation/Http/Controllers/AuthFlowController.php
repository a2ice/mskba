<?php

namespace App\Modules\Identity\Presentation\Http\Controllers;

use App\Modules\Identity\Application\Services\AuthChallengeManager;
use App\Modules\Identity\Application\UseCases\ResolveLoginHandler;
use App\Modules\Identity\Application\UseCases\VerifyLoginHandler;
use App\Modules\Identity\Presentation\Http\Requests\ResolveLoginRequest;
use App\Modules\Identity\Presentation\Http\Requests\VerifyLoginRequest;
use Illuminate\Http\JsonResponse;

class AuthFlowController
{
    public function __construct(
        private readonly AuthChallengeManager $authChallengeManager,
        private readonly ResolveLoginHandler $resolveLoginHandler,
        private readonly VerifyLoginHandler $verifyLoginHandler,
    ) {
    }

    public function resolveLogin(ResolveLoginRequest $request): JsonResponse
    {
        $payload = $this->resolveLoginHandler->handle((string) $request->validated('login'));

        $challenge = null;
        if (isset($payload['user_id'], $payload['verify_flow'])) {
            $challenge = $this->authChallengeManager->issue(
                $request->session(),
                (int) $payload['user_id'],
                (string) $payload['verify_flow'],
            );
        }

        return response()->json([
            'status' => $payload['status'],
            'message' => $payload['message'],
            'challenge' => $challenge,
        ], $payload['httpStatus']);
    }

    public function verify(VerifyLoginRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $challengeKey = (string) ($validated['challenge'] ?? '');
        $challenge = $this->authChallengeManager->resolve($request->session(), $challengeKey);
        if ($challenge === null) {
            return response()->json([
                'status' => 'verification_failed',
                'message' => 'Сессия входа истекла. Начните вход заново.',
            ], 422);
        }

        $payload = $this->verifyLoginHandler->handle(
            (int) $challenge['user_id'],
            (string) $challenge['flow'],
            isset($validated['password']) ? (string) $validated['password'] : null,
            isset($validated['code']) ? (string) $validated['code'] : null,
        );

        if (($payload['status'] ?? null) === 'verified') {
            $this->authChallengeManager->consume($request->session(), $challengeKey);
        }

        return response()->json([
            'status' => $payload['status'],
            'message' => $payload['message'],
        ], $payload['httpStatus']);
    }
}
