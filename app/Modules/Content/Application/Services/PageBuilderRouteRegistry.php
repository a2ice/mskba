<?php

namespace App\Modules\Content\Application\Services;

use Illuminate\Http\Request;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * A read-only view of the actual, route-cached Laravel collection.
 * Only code-reviewed, exact GET/HEAD routes may be replaced.
 */
final class PageBuilderRouteRegistry
{
    private const RESERVED = [
        'account', 'admin', 'backoffice', 'api', 'login', 'register', 'logout',
        'dashboard', 'storage', 'assets', 'page-builder', 'site-summary',
        'integrations', 'r',
    ];

    /** @return array{type:string, route_name:?string, action:?string, source:?string, eligible:bool, conflict:bool} */
    public function inspect(string $path): array
    {
        $empty = ['type' => 'static', 'route_name' => null, 'action' => null,
            'source' => null, 'eligible' => false, 'conflict' => false];

        if ($path === '/') {
            return $empty;
        }

        $first = explode('/', ltrim($path, '/'))[0];
        if (in_array($first, self::RESERVED, true)) {
            return [...$empty, 'type' => 'protected', 'conflict' => true];
        }

        try {
            $route = Route::getRoutes()->match(Request::create($path, 'GET'));
        } catch (NotFoundHttpException|MethodNotAllowedHttpException) {
            return $empty;
        }

        if (! $route instanceof LaravelRoute
            || $route->getName() === 'page-builder.dynamic') {
            return $empty;
        }

        $name = $route->getName();
        $settings = is_string($name) ? config('page-builder.overrides.'.$name) : null;
        $exact = '/'.trim($route->uri(), '/') === $path;
        $eligible = $exact
            && is_array($settings)
            && ($settings['path'] ?? null) === $path
            && in_array('GET', $route->methods(), true)
            && in_array('HEAD', $route->methods(), true);

        return [
            'type' => $eligible ? 'public_index' : 'protected',
            'route_name' => is_string($name) ? $name : null,
            'action' => $route->getActionName(),
            'source' => $eligible ? (string) ($settings['source'] ?? '') : null,
            'eligible' => $eligible,
            'conflict' => true,
        ];
    }

    /** @return array{type:string, route_name:?string} */
    public function forPublication(string $path): array
    {
        if (! preg_match('~\A/(?:[a-z0-9]+(?:-[a-z0-9]+)*)?\z~D', $path)) {
            throw ValidationException::withMessages([
                'path' => 'Пока поддерживаются только одноуровневые URL вида /about.',
            ]);
        }
        $info = $this->inspect($path);
        if ($info['conflict'] && ! $info['eligible']) {
            throw ValidationException::withMessages([
                'path' => 'Этот Laravel-маршрут защищён от CMS-переопределения. '
                    .'Динамические страницы, формы и системные маршруты будут подключаться отдельно.',
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
            return 'Новая публичная страница. После публикации доступна по этому URL без изменения routes/web.php.';
        }
        if (! $info['eligible']) {
            return 'Защищённый Laravel-маршрут: CMS не может его заменить на этом этапе.';
        }

        return sprintf(
            'Обнаружена Laravel-версия: %s → %s (%s). После публикации CMS получит приоритет. '
                .'Исходный контроллер сохранится для мгновенного возврата.',
            $info['route_name'], $info['action'], $info['source'],
        );
    }
}
