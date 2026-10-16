<?php

namespace App\Modules\Content\Application\Services;

use Illuminate\Http\Request;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Inspect the actual registered Laravel routes (including route:cache).
 * Static, named, public GET/HEAD routes may be owned by the Page Builder.
 * No per-page route declarations or allowlist of public URLs is required.
 */
final class PageBuilderRouteRegistry
{
    /**
     * Never let CMS short-circuit these application/security entry points.
     * This is a denylist of systems, not an allowlist of public content.
     */
    private const PROTECTED_ROOTS = [
        'account', 'admin', 'backoffice', 'api', 'auth', 'login', 'register',
        'logout', 'dashboard', 'storage', 'assets', 'page-builder',
        'site-summary', 'integrations', 'r', 'up', 'sanctum', 'livewire',
        'horizon', 'telescope', 'broadcasting', 'oauth', 'webhooks', 'password',
        'password-reset', 'csrf',
    ];

    /** @return array{type:string,route_name:?string,action:?string,source:?string,eligible:bool,conflict:bool} */
    public function inspect(string $path): array
    {
        $empty = [
            'type' => 'static', 'route_name' => null, 'action' => null,
            'source' => null, 'eligible' => false, 'conflict' => false,
        ];

        if ($path === '/') {
            // Home uses its existing explicit opt-in and fallback Blade.
            return $empty;
        }

        if (preg_match('~\A/[a-z0-9]+(?:-[a-z0-9]+)*\z~D', $path) !== 1) {
            return [...$empty, 'type' => 'protected', 'conflict' => true];
        }

        $root = ltrim($path, '/');
        if (in_array($root, self::PROTECTED_ROOTS, true)) {
            return [...$empty, 'type' => 'protected', 'conflict' => true];
        }

        try {
            $route = Route::getRoutes()->match(Request::create($path, 'GET'));
        } catch (NotFoundHttpException|MethodNotAllowedHttpException) {
            return $empty;
        }

        if (! $route instanceof LaravelRoute || $route->isFallback()
            || $route->getName() === 'page-builder.dynamic') {
            return $empty;
        }

        $name = $route->getName();
        $staticPath = '/'.trim($route->uri(), '/');
        // Middleware executing before the action MUST NOT bypass authorization,
        // rate limits, signed URL checks or any other route-scoped guards.
        // Only the normal 'web' group is permitted at this stage.
        $middlewares = $route->gatherMiddleware();
        $public = count(array_diff($middlewares, ['web'])) === 0;

        $eligible = $staticPath === $path
            && is_string($name) && $name !== ''
            && in_array('GET', $route->methods(), true)
            && in_array('HEAD', $route->methods(), true)
            && $public;

        return [
            'type' => $eligible ? 'public_index' : 'protected',
            'route_name' => is_string($name) ? $name : null,
            'action' => $route->getActionName(),
            'source' => 'Laravel route: '.($name ?: $route->uri()),
            'eligible' => $eligible,
            'conflict' => true,
        ];
    }

    /** @return array{type:string,route_name:?string} */
    public function forPublication(string $path): array
    {
        if (preg_match('~\A/(?:[a-z0-9]+(?:-[a-z0-9]+)*)?\z~D', $path) !== 1) {
            throw ValidationException::withMessages([
                'path' => 'Пока поддерживаются только одноуровневые URL вида /about.',
            ]);
        }

        $info = $this->inspect($path);
        if ($info['conflict'] && ! $info['eligible']) {
            throw ValidationException::withMessages([
                'path' => 'Маршрут защищён: авторизация, служебные операции, '
                    .'параметризованные страницы и формы пока не переопределяются CMS.',
            ]);
        }

        return [
            'type' => $info['eligible'] ? 'public_index' : 'static',
            'route_name' => $info['eligible'] ? $info['route_name'] : null,
        ];
    }

    public function mayOverride(string $name, string $path): bool
    {
        $info = $this->inspect($path);

        return $info['eligible'] && $info['route_name'] === $name;
    }

    public function description(string $path): string
    {
        $info = $this->inspect($path);

        if ($path === '/') {
            return 'Главная: специальный маршрут Laravel, уже подключённый к Page Builder.';
        }
        if (! $info['conflict']) {
            return 'Новый URL. После публикации страница будет доступна через CMS без изменения routes/web.php.';
        }
        if (! $info['eligible']) {
            return 'Защищённый маршрут Laravel. Переопределение через CMS недоступно на этом этапе.';
        }

        return sprintf(
            'Есть исходная Laravel-версия: %s → %s. Публикация включит CMS-приоритет; '
                .'действие «Вернуть Laravel» восстановит старую страницу без деплоя.',
            $info['route_name'], $info['action'],
        );
    }
}
