<?php

namespace App\Modules\Identity\Presentation\Http\Support;

use Illuminate\Http\Request;

final class AuthenticationRedirectNotice
{
    public function __construct(
        private readonly SafeAuthenticationRedirectResolver $redirects,
    ) {}

    /**
     * @return array{url: string, label: string}|null
     */
    public function for(Request $request): ?array
    {
        if ($request->query->has('redirect_to')) {
            $this->redirects->rememberIntended($request, $request->query('redirect_to'));
        }

        $target = $this->redirects->peek($request);
        if ($target === null) {
            return null;
        }

        return [
            'url' => $target,
            'label' => $this->labelFor($target),
        ];
    }

    private function labelFor(string $url): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);

        return match (true) {
            str_starts_with($path, '/events/create/wizard') => 'бронирование площадки',
            str_starts_with($path, '/events/') => 'страница мероприятия',
            $path === '/events' => 'мероприятия',
            str_starts_with($path, '/tournaments/create') => 'создание турнира',
            str_starts_with($path, '/tournaments/') => 'страница турнира',
            $path === '/tournaments' => 'турниры',
            str_starts_with($path, '/teams/create') => 'создание команды',
            str_starts_with($path, '/teams/') => 'страница команды',
            $path === '/teams' => 'команды',
            str_starts_with($path, '/venues/') => 'страница площадки',
            $path === '/venues' => 'площадки',
            str_starts_with($path, '/coordination/') => 'согласование мероприятия',
            $path === '/coordination' => 'согласования',
            str_starts_with($path, '/account') => 'личный кабинет',
            default => 'нужная страница',
        };
    }
}
