<?php

namespace App\Modules\Identity\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Application\UseCases\AuthHandler;
use App\Modules\Identity\Presentation\Http\Requests\LoginRequest;
use Illuminate\Http\RedirectResponse;

class AuthController extends Controller
{
    public function login(LoginRequest $request, AuthHandler $authHandler): RedirectResponse
    {
        $validated = $request->validated();

        $result = $authHandler->login(
            login: $validated['login'],
            password: $validated['password'],
            remember: $request->boolean('remember'),
        );

        if ($result->status === 'error') {
            return back()->withInput($request->only('login', 'remember'))->withErrors(['login' => $result->message]);
        }

        if ($result->status === 'warning') {
            return redirect()->intended('/')->with('warning', $result->message);
        }

        return redirect()->intended('/')->with('success', $result->message);
    }

    public function logout(AuthHandler $authHandler): RedirectResponse
    {
        $authHandler->logout();

        return redirect('/')->with('success', 'Вы успешно вышли из системы.');
    }
}
