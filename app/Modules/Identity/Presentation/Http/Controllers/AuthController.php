<?php

namespace App\Modules\Identity\Presentation\Http\Controllers;

use App\Modules\Identity\Application\UseCases\LoginHandler;
use App\Modules\Identity\Presentation\Http\Requests\LoginRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\RedirectResponse;

class AuthController
{
    public function __construct() 
    { }

    public function login(LoginRequest $request, LoginHandler $loginHandler): JsonResponse
    {
        $validated = $request->validated();

        $payload = $loginHandler->handle(
            (string) $validated['login'],
            (string) $validated['password'],
            $request->boolean('remember'),
        );

        if ($payload['status'] === 'authenticated') {
            $request->session()->regenerate();
        }

        return response()->json([
            'status' => $payload['status'],
            'message' => $payload['message'],
        ], $payload['httpStatus']);
    }

    public function logout(): RedirectResponse
    {
        Auth::logout();
        request()->session()->invalidate();
        request()->session()->regenerateToken();

        return redirect()->route('home');
    }
}
