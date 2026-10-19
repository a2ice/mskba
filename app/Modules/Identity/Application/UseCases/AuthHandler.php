<?php

namespace App\Modules\Identity\Application\UseCases;

use App\Modules\Identity\Application\DTO\LoginResponseDTO;
use App\Modules\Identity\Application\Services\CredentialAuthenticationService;
use Illuminate\Support\Facades\Auth;

class AuthHandler
{
    public function __construct(
        private readonly CredentialAuthenticationService $credentials,
    ) {}

    public function login(string $login, string $password, bool $remember): LoginResponseDTO
    {
        $result = $this->credentials->verify($login, $password);

        if (! $result->successful()) {
            return new LoginResponseDTO(
                status: $result->status,
                message: $result->message,
                httpStatus: $result->httpStatus,
            );
        }

        Auth::login($result->user, $remember);
        request()->session()->regenerate();
        $this->credentials->recordFirstLogin($result->user);

        return new LoginResponseDTO(
            status: $result->status,
            message: $result->message,
            httpStatus: $result->httpStatus,
        );
    }

    public function logout(): void
    {
        Auth::logout();
        request()->session()->invalidate();
        request()->session()->regenerateToken();
    }
}
