<?php

namespace App\Modules\Identity\Application\Services;

use App\Modules\Identity\Domain\Enums\UserOperationalPermissionEnum;
use Illuminate\Http\Request;

final class OperationalPermissionIntentResolver
{
    public function __construct(
        private readonly UserOperationalPermissionChecker $permissions,
    ) {}

    public function consumeAllowedReturnUrl(Request $request): ?string
    {
        $intent = $request->session()->get('operational_permission_intent');
        if (! is_array($intent)) {
            return null;
        }

        $user = $request->user()?->canonical();
        $permission = UserOperationalPermissionEnum::tryFrom((string) ($intent['permission'] ?? ''));

        if ($user === null || $permission === null) {
            $request->session()->forget('operational_permission_intent');

            return null;
        }

        if (! $this->permissions->allows($user, $permission)) {
            return null;
        }

        $returnUrl = (string) ($intent['return_url'] ?? '');
        if (! $this->isSafeInternalPath($returnUrl)) {
            $returnUrl = $permission === UserOperationalPermissionEnum::CREATE_TOURNAMENT
                ? route('tournaments.create', absolute: false)
                : route('events.wizard', absolute: false);
        }

        $request->session()->forget('operational_permission_intent');

        return $returnUrl;
    }

    private function isSafeInternalPath(string $url): bool
    {
        return str_starts_with($url, '/') && ! str_starts_with($url, '//');
    }
}
