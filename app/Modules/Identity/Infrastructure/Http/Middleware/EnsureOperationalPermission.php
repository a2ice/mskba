<?php

namespace App\Modules\Identity\Infrastructure\Http\Middleware;

use App\Modules\Identity\Application\Services\UserOperationalPermissionChecker;
use App\Modules\Identity\Application\Services\VerifiedContactOperationalPermissionGranter;
use App\Modules\Identity\Domain\Enums\UserOperationalPermissionEnum;
use Closure;
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

        if ($user->isBlocked() || $user->trashed()) {
            abort(403, 'Действие недоступно для заблокированного или удаленного аккаунта.');
        }

        $snapshot = $user->operationalPermissions()
            ->where('permission', $permission->value)
            ->first();

        if ($snapshot !== null) {
            $request->session()->forget('operational_permission_intent');

            if ($snapshot->is_allowed) {
                return $next($request);
            }

            return redirect()
                ->route($user->isConfirmed() ? 'account.contacts' : 'account.confirmation')
                ->with('error', $this->explicitDenialMessage($permission));
        }

        // Role-aware defaults must be evaluated before contact verification.
        // ADMIN/SUPERADMIN have every operational permission enabled by default,
        // while an explicit snapshot above can still override that default.
        if ($this->permissions->allows($user, $permission)) {
            $request->session()->forget('operational_permission_intent');

            return $next($request);
        }

        $hasVerifiedContact = $this->verifiedContactGranter->hasVerifiedContact($user);
        if ($hasVerifiedContact) {
            // Eventual-consistency safety net: old verified identities or canonical merges
            // may not have received the initial snapshot yet. Explicit false snapshots
            // were handled above and are never overwritten by grantMissing().
            $this->verifiedContactGranter->grantMissing($user);

            if ($this->permissions->allows($user, $permission)) {
                $request->session()->forget('operational_permission_intent');

                return $next($request);
            }

            // An administrator may have written an explicit denial concurrently
            // while the missing grant was being resolved. Never convert that denial
            // into another verification prompt.
            $request->session()->forget('operational_permission_intent');

            return redirect()
                ->route($user->isConfirmed() ? 'account.contacts' : 'account.confirmation')
                ->with('error', $this->explicitDenialMessage($permission));
        }

        $title = $this->verificationTitle($permission);
        $message = 'Подтвержденный контакт нужен, чтобы другие участники могли доверять организатору и при необходимости связаться с ним.';

        $request->session()->put('operational_permission_intent', [
            'permission' => $permission->value,
            'return_url' => $this->returnUrl($request, $permission),
            'title' => $title,
            'message' => $message,
        ]);

        return redirect()
            ->route($user->isConfirmed() ? 'account.contacts' : 'account.confirmation')
            ->with('info', $title.'. '.$message.' После подтверждения мы автоматически вернем вас к созданию.');
    }

    private function returnUrl(Request $request, UserOperationalPermissionEnum $permission): string
    {
        if ($permission === UserOperationalPermissionEnum::CREATE_TOURNAMENT) {
            return route('tournaments.create', absolute: false);
        }

        $type = (string) ($request->input('type') ?? $request->query('type', ''));
        $allowedTypes = ['game', 'training', 'game_training'];
        $parameters = in_array($type, $allowedTypes, true) ? ['type' => $type] : [];
        $venueId = (int) ($request->input('venue_id') ?? $request->query('venue_id', 0));
        if ($venueId > 0) {
            $parameters['venue_id'] = $venueId;
        }

        return route('events.wizard', $parameters, false);
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
