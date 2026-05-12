<?php

namespace App\Modules\Identity\Presentation\Http\Controllers;

use App\Modules\Identity\Application\UseCases\ResolveLoginHandler;
use App\Modules\Identity\Presentation\Http\Requests\ResolveLoginRequest;
use Illuminate\Http\JsonResponse;

class AuthFlowController
{
    public function __construct(
        private readonly ResolveLoginHandler $resolveLoginHandler,
    ) {
    }

    public function resolveLogin(ResolveLoginRequest $request): JsonResponse
    {
        $payload = $this->resolveLoginHandler->handle((string) $request->validated('login'));

        return response()->json([
            'status' => $payload['status'],
            'message' => $payload['message'],
        ], $payload['httpStatus']);
    }
}
