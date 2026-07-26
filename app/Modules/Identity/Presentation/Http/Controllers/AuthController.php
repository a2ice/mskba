<?php

namespace App\Modules\Identity\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Application\DTO\PrivacyConsentDTO;
use App\Modules\Identity\Application\UseCases\AuthHandler;
use App\Modules\Identity\Application\UseCases\RegisterUserHandler;
use App\Modules\Identity\Presentation\Http\Requests\LoginRequest;
use App\Modules\Identity\Presentation\Http\Requests\RegisterRequest;
use App\Modules\Identity\Presentation\Http\Support\SafeAuthenticationRedirectResolver;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

class AuthController extends Controller
{
    public function login(
        LoginRequest $request,
        AuthHandler $authHandler,
        SafeAuthenticationRedirectResolver $redirects,
    ): RedirectResponse|JsonResponse {
        $validated = $request->validated();

        $result = $authHandler->login(
            login: $validated['login'],
            password: $validated['password'],
            remember: $request->boolean('remember'),
        );

        if ($result->status === 'error' && $this->shouldReturnJson($request)) {
            return response()->json([
                'status' => $result->status,
                'message' => $result->message,
                'redirect_url' => null,
            ], $result->httpStatus);
        }

        if ($result->status === 'error') {
            return back()->withInput($request->only('login', 'remember'))->withErrors(['login' => $result->message]);
        }

        $redirectTo = $redirects->resolve($request, $validated['redirect_to'] ?? null);

        if ($this->shouldReturnJson($request)) {
            return response()->json([
                'status' => $result->status,
                'message' => $result->message,
                'redirect_url' => $redirectTo,
            ], $result->httpStatus);
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

    public function register(
        RegisterRequest $request,
        RegisterUserHandler $registerUser,
        AuthHandler $authHandler,
        SafeAuthenticationRedirectResolver $redirects,
    ): RedirectResponse|JsonResponse {
        $validated = $request->validated();

        $user = $registerUser->handle(
            username: $validated['username'],
            password: $validated['password'],
            participantRole: $request->participantRole(),
            profile: $request->profile(),
            privacyConsent: new PrivacyConsentDTO(
                documentVersion: (string) config('legal.privacy_policy_version'),
                acceptedAt: CarbonImmutable::now(),
                source: 'site_registration',
                ipAddress: $request->ip(),
                userAgent: $request->userAgent(),
            ),
        );

        $authHandler->login(
            login: $user->username,
            password: $validated['password'],
            remember: false,
        );

        $redirectTo = $redirects->resolve(
            request: $request,
            requestedUrl: $validated['redirect_to'] ?? null,
            fallbackUrl: route('account'),
        );

        if ($this->shouldReturnJson($request)) {
            return response()->json([
                'status' => 'success',
                'message' => 'Регистрация завершена.',
                'login' => $user->username,
                'redirect_url' => $redirectTo,
            ], 201);
        }

        return redirect()->to($redirectTo)->with('success', 'Регистрация завершена.');
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
