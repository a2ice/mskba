<?php

namespace App\Modules\Identity\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Application\UseCases\AuthHandler;
use App\Modules\Identity\Application\UseCases\RegisterUserHandler;
use App\Modules\Identity\Presentation\Http\Requests\LoginRequest;
use App\Modules\Identity\Presentation\Http\Requests\RegisterRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

class AuthController extends Controller
{
    public function login(LoginRequest $request, AuthHandler $authHandler): RedirectResponse|JsonResponse
    {
        $validated = $request->validated();

        $result = $authHandler->login(
            login: $validated['login'],
            password: $validated['password'],
            remember: $request->boolean('remember'),
        );

        $redirectedFrom = url()->previous();
        $redirectTo = url('/');

        // Prevent open redirect vulnerabilities
        if ($redirectedFrom && str_starts_with($redirectedFrom, url('/'))) {
            $redirectTo = $redirectedFrom;
        }

        if ($this->shouldReturnJson($request)) {
            return response()->json([
                'status' => $result->status,
                'message' => $result->message,
                'redirect_url' => $result->status === 'error'
                    ? null
                    : $redirectTo,
            ], $result->httpStatus);
        }

        if ($result->status === 'error') {
            return back()->withInput($request->only('login', 'remember'))->withErrors(['login' => $result->message]);
        }

        if ($result->status === 'warning') {
            return redirect()->intended($redirectTo)->with('warning', $result->message);
        }

        return redirect()->intended($redirectTo)->with('success', $result->message);
    }

    public function logout(AuthHandler $authHandler): RedirectResponse|JsonResponse
    {
        $authHandler->logout();

        return redirect('/')->with('success', 'Вы успешно вышли из системы.');
    }

    public function register(RegisterRequest $request, RegisterUserHandler $registerUser): RedirectResponse|JsonResponse
    {
        $validated = $request->validated();

        $user = $registerUser->handle(
            username: $validated['username'],
            password: $validated['password'],
            participantRole: $request->participantRole(),
        );

        if ($this->shouldReturnJson($request)) {
            return response()->json([
                'status' => 'success',
                'message' => 'Регистрация завершена. Теперь можно войти.',
                'login' => $user->username,
            ], 201);
        }

        return redirect()
            ->route('login')
            ->with('success', 'Регистрация завершена. Теперь можно войти.');
    }

    public function restore(): void
    {
        $message = 'В данный момент мы работаем над восстановлением доступа. Пожалуйста, обратитесь в поддержку для получения помощи.';

        if (request()->expectsJson() || request()->ajax() || request()->wantsJson()) {
            response()->json([
                'status' => 'error',
                'message' => $message,
            ], 503)->send();
            exit;
        }

        redirect()->route('login')->with('error', $message)->send();

        exit;
    }

    private function shouldReturnJson(LoginRequest|RegisterRequest $request): bool
    {
        return $request->expectsJson()
            || $request->ajax()
            || $request->wantsJson();
    }
}
