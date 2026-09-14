<?php

namespace App\Http\Middleware;

use App\Modules\Identity\Application\Services\UserOperationalPermissionChecker;
use App\Modules\Identity\Application\Services\VerifiedContactOperationalPermissionGranter;
use App\Modules\Identity\Domain\Enums\UserOperationalPermissionEnum;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleEnum;
use App\Presentation\Creation\CreationPages;
use App\Presentation\Theming\ThemeResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Presentation gate only: write endpoints retain their own authorization. */
final class ShowCreationPageAccess
{
    public function __construct(
        private readonly UserOperationalPermissionChecker $permissions,
        private readonly VerifiedContactOperationalPermissionGranter $contacts,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $topic = CreationPages::TOPICS[$request->route()?->getName()] ?? null;
        if ($topic === null || ! $request->isMethodSafe()) {
            return $next($request);
        }
        if ($topic === 'sections') {
            abort_unless(config('features.sports_sections.enabled'), 404);
        }

        $user = $request->user()?->canonical();
        $requirements = [];
        if ($user === null) {
            // Keep the exact local destination, including wizard/booking parameters.
            $request->session()->put('url.intended', $request->getSchemeAndHttpHost().$request->getRequestUri());
        } elseif ($user->isBlocked() || $user->trashed()) {
            $requirements[] = ['message' => 'Создание недоступно: аккаунт заблокирован или удалён. Обратитесь к администратору.', 'route' => null, 'label' => null];
        } else {
            if (in_array($topic, ['teams', 'tournaments', 'sections'], true) && ! $user->isConfirmed()) {
                $requirements[] = ['message' => 'Для создания нужен подтверждённый аккаунт.', 'route' => 'account.confirmation', 'label' => 'Подтвердить аккаунт'];
            }
            if ($topic === 'sections' && ! $user->hasActiveRole(UserParticipationRoleEnum::COACH->value)) {
                $requirements[] = ['message' => 'Для создания секции нужна роль тренера.', 'route' => 'account.roles', 'label' => 'Выбрать роль тренера'];
            }
            $permission = match ($topic) {
                'events' => UserOperationalPermissionEnum::CREATE_EVENT,
                'tournaments' => UserOperationalPermissionEnum::CREATE_TOURNAMENT,
                'teams' => UserOperationalPermissionEnum::CREATE_TEAM,
                'coordination' => UserOperationalPermissionEnum::CREATE_COORDINATION,
                default => null,
            };
            if ($permission !== null && ! $this->permissions->allows($user, $permission)) {
                $snapshot = $user->operationalPermissions()->where('permission', $permission->value)->first();
                if ($snapshot === null && in_array($topic, ['events', 'tournaments'], true)) {
                    // Reuse the existing serialized grant; never overwrite an explicit denial.
                    $this->contacts->grantMissing($user);
                }
                if (! $this->permissions->allows($user, $permission)) {
                    $snapshot = $user->operationalPermissions()->where('permission', $permission->value)->first();
                    $requirements[] = $snapshot !== null
                        ? ['message' => 'Создание отключено для вашего аккаунта администратором. Обратитесь к администратору для восстановления доступа.', 'route' => null, 'label' => null]
                        : ['message' => 'Чтобы создавать мероприятия и турниры, подтвердите хотя бы один контакт.', 'route' => 'account.contacts', 'label' => 'Подтвердить контакт'];
                }
            }
        }

        if ($user !== null && $requirements === []) {
            return $next($request);
        }

        return ThemeResolver::page('creation-access', [
            'creationTopic' => $topic,
            'creationGuide' => config('creation-guides.'.$topic),
            'requirements' => $requirements,
        ]);
    }
}
