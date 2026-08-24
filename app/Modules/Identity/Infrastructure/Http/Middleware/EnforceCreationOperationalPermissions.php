<?php

namespace App\Modules\Identity\Infrastructure\Http\Middleware;

use App\Modules\Identity\Application\Services\UserOperationalPermissionChecker;
use App\Modules\Identity\Application\Services\VerifiedContactOperationalPermissionGranter;
use App\Modules\Identity\Domain\Enums\UserOperationalPermissionEnum;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnforceCreationOperationalPermissions
{
    public function __construct(
        private readonly EnsureOperationalPermission $guard,
        private readonly UserOperationalPermissionChecker $permissions,
        private readonly VerifiedContactOperationalPermissionGranter $verifiedContactGranter,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $routeName = $request->route()?->getName();
        $permission = match ($routeName) {
            'events.create',
            'events.store',
            'events.wizard',
            'events.wizard.teams',
            'events.wizard.venues' => UserOperationalPermissionEnum::CREATE_EVENT,
            'tournaments.create',
            'tournaments.store' => UserOperationalPermissionEnum::CREATE_TOURNAMENT,
            default => null,
        };

        if ($permission !== null) {
            return $this->guard->handle($request, $next, $permission->value);
        }

        $response = $next($request);

        if ($routeName !== 'account.confirmation.contacts.verification.confirm') {
            return $response;
        }

        $intent = $request->session()->get('operational_permission_intent');
        if (! is_array($intent)) {
            return $response;
        }

        $user = $request->user()?->canonical();
        $intendedPermission = UserOperationalPermissionEnum::tryFrom((string) ($intent['permission'] ?? ''));
        if ($user === null || $intendedPermission === null) {
            $request->session()->forget('operational_permission_intent');

            return $response;
        }

        $this->verifiedContactGranter->grantMissing($user);

        if (! $this->verifiedContactGranter->hasVerifiedContact($user)) {
            return $response;
        }

        if (! $this->permissions->allows($user, $intendedPermission)) {
            $request->session()->forget('operational_permission_intent');

            return redirect()
                ->route('account.confirmation')
                ->with('error', 'Контакт подтвержден, но создание по-прежнему отключено операционным правом. Обратитесь к администратору.');
        }

        $returnUrl = (string) ($intent['return_url'] ?? '');
        if (! str_starts_with($returnUrl, '/') || str_starts_with($returnUrl, '//')) {
            $returnUrl = $intendedPermission === UserOperationalPermissionEnum::CREATE_TOURNAMENT
                ? route('tournaments.create', absolute: false)
                : route('events.wizard', absolute: false);
        }

        $request->session()->forget('operational_permission_intent');

        return redirect($returnUrl)
            ->with('status', 'Контакт подтвержден. Право на создание включено — можно продолжить.');
    }
}
