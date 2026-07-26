<?php

namespace App\Modules\Identity\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Application\DTO\PrivacyConsentDTO;
use App\Modules\Identity\Application\UseCases\AuthHandler;
use App\Modules\Identity\Application\UseCases\RegisterUserHandler;
use App\Modules\Identity\Presentation\Http\Requests\LoginRequest;
use App\Modules\Identity\Presentation\Http\Requests\RegisterRequest;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

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

        $redirectTo = $this->safeRedirectUrl($request, $validated['redirect_to'] ?? null);

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

        $redirectTo = $this->safeRedirectUrl(
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

    private function safeRedirectUrl(Request $request, mixed $requestedUrl, ?string $fallbackUrl = null): string
    {
        if (is_string($requestedUrl)) {
            $requestedUrl = trim($requestedUrl);

            if (str_starts_with($requestedUrl, '/') && ! str_starts_with($requestedUrl, '//')) {
                return url($requestedUrl);
            }

            if ($this->isSameOriginUrl($requestedUrl)) {
                return $requestedUrl;
            }
        }

        $intendedUrl = $request->session()->pull('url.intended');

        if (is_string($intendedUrl) && $this->isSameOriginUrl($intendedUrl)) {
            return $intendedUrl;
        }

        if ($fallbackUrl !== null && $this->isSameOriginUrl($fallbackUrl)) {
            return $fallbackUrl;
        }

        $redirectedFrom = url()->previous();

        return $this->isSameOriginUrl($redirectedFrom) ? $redirectedFrom : url('/');
    }

    private function isSameOriginUrl(string $url): bool
    {
        $target = parse_url($url);
        $origin = parse_url(url('/'));

        if ($target === false || $origin === false) {
            return false;
        }

        return ($target['scheme'] ?? null) === ($origin['scheme'] ?? null)
            && ($target['host'] ?? null) === ($origin['host'] ?? null)
            && ($target['port'] ?? null) === ($origin['port'] ?? null);
    }
}
