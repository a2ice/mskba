<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

final class HandleMskbaAppInertiaRequests extends Middleware
{
    protected $rootView = 'theme::layouts.inertia';

    public function share(Request $request): array
    {
        return array_merge(parent::share($request), [
            'auth' => fn (): array => [
                'user' => $request->user()
                    ? ['username' => $request->user()->username]
                    : null,
            ],
        ]);
    }
}
