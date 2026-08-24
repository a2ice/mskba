<?php

namespace App\Modules\Identity\Infrastructure\Http\Middleware;

use App\Modules\Identity\Application\Services\UserOperationalPermissionChecker;
use App\Modules\Identity\Application\Services\VerifiedContactOperationalPermissionGranter;
use App\Modules\Identity\Domain\Enums\UserOperationalPermissionEnum;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureOperationalPermission
{
    public function __construct(
        private readonly UserOperationalPermissionChecker $permissions,
        private readonly VerifiedContactOperationalPermissionGranter $verifiedContactGranter,
    ) {}

    public function handle(Request $request, Closure $next, string $permissionValue): Response
    {
        $permission = UserOperationalPermissionEnum::tryFrom($permissionValue);
        abort_if($permission === null, 500, 'Неизвестное операционное право.');

        $user = $request->user()?->canonical();
        if ($user === null) {
            return redirect()->route('login');
        }

        $hasVerifiedContact = $this->verifiedContactGranter->hasVerifiedContact($user);
        if ($hasVerifiedContact) {
            // Eventual-consistency safety net: old verified identities or canonical merges
            // may not have received the initial snapshot yet. Existing false snapshots
            // are deliberately preserved by grantMissing().
            $this->verifiedContactGranter->grantMissing($user);
        }

        if ($this->permissions->allows($user, $permission)) {
            $request->session()->forget('operational_permission_intent');

            return $next($request);
        }

        if ($user->isBlocked() || $user->trashed()) {
            abort(403, 'Действие недоступно для заблокированного или удаленного аккаунта.');
        }

        if ($hasVerifiedContact) {
            $request->session()->forget('operational_permission_intent');

            return redirect()
                ->route('account.confirmation')
                ->with('error', $this->explicitDenialMessage($permission));
        }

        $request->session()->put('operational_permission_intent', [
            'permission' => $permission->value,
            'return_url' => $this->returnUrl($request, $permission),
            'title' => $this->verificationTitle($permission),
            'message' => 'Подтвержденный контакт нужен, чтобы другие участники могли доверять организатору и при необходимости связаться с ним.',
        ]);

        return redirect()->route('account.confirmation');
    }

    private function returnUrl(Request $request, UserOperationalPermissionEnum $permission): string
    {
        if ($permission === UserOperationalPermissionEnum::CREATE_TOURNAMENT) {
            return route('tournaments.create', absolute: false);
        }

        $type = (string) ($request->input('type') ?? $request->query('type', ''));
        $allowedTypes = ['game', 'training', 'game_training'];

        return route(
            'events.wizard',
            in_array($type, $allowedTypes, true) ? ['type' => $type] : [],
            false,
        );
    }

    private function verificationTitle(UserOperationalPermissionEnum $permission): string
    {
        return match ($permission) {
            UserOperationalPermissionEnum::CREATE_TOURNAMENT => 'Подтвердите контакт, чтобы создавать турниры',
            default => 'Подтвердите контакт, чтобы создавать игры и тренировки',
        };
    }

    private function explicitDenialMessage(UserOperationalPermissionEnum $permission): string
    {
        $subject = $permission === UserOperationalPermissionEnum::CREATE_TOURNAMENT
            ? 'Создание турниров'
            : 'Создание мероприятий';

        return $subject.' отключено для вашего аккаунта операционным правом. Если это ограничение установлено ошибочно, обратитесь к администратору.';
    }
}
