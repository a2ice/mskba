<?php

namespace App\Presentation\Theming;

use Illuminate\Http\Response;
use Illuminate\Support\Facades\View;

class ThemeResolver
{
    /**
     * Already-published routes must keep rendering real content while their
     * native mskba_app templates are being implemented. This is deliberately
     * restricted to read-only public pages, never account/backoffice views.
     */
    private const LEGACY_PUBLIC_PAGES = [
        'news.index', 'news.show',
        'events.index', 'events.show', 'events.game-show',
        'events.game-live', 'events.game-qr-join',
        'tournaments.index', 'tournaments.show', 'tournaments.on-site-registration',
        'sports-sections.index', 'sports-sections.show',
        'participants.index',
        'coordination.index', 'coordination.show',
        'faq.index', 'faq.show', 'faq.search', 'faq.welcome', 'faq.creation',
    ];

    private bool $renderingLegacyPublicPage = false;

    public function __construct(
        private readonly array $config,
    ) {}

    public function active(): string
    {
        // During a legacy page render the original layouts resolve their own
        // CSS/JS and partials, not those of mskba_app.
        if ($this->renderingLegacyPublicPage) {
            return 'mskba_dark';
        }

        $theme = $this->config['active'] ?? 'mskba_dark';
        $items = $this->config['items'] ?? [];

        return is_string($theme) && isset($items[$theme]) ? $theme : 'mskba_dark';
    }

    public function name(): string
    {
        $name = $this->config['items'][$this->active()]['name'] ?? null;

        return is_string($name) && $name !== '' ? $name : $this->active();
    }

    public function viewsPath(): string
    {
        return resource_path('themes/'.$this->active().'/views');
    }

    public function view(string $name): string
    {
        return 'theme::'.$name;
    }

    public static function page(string $name, array $data = []): Response
    {
        $statusCode = self::normalizeStatusCode($data['error']['code'] ?? 200);
        $view = 'theme::pages.'.$name;
        $viewExists = view()->exists($view);
        if (! $viewExists) {
            $resolver = app(self::class);
            $legacyFile = resource_path('themes/mskba_dark/views/pages/'.str_replace('.', '/', $name).'.blade.php');
            if ($resolver->active() === 'mskba_app'
                && request()->isMethod('GET')
                && in_array($name, self::LEGACY_PUBLIC_PAGES, true)
                && is_file($legacyFile)) {
                $resolver->renderingLegacyPublicPage = true;
                View::replaceNamespace('theme', resource_path('themes/mskba_dark/views'));
                // The finder memoizes resolved paths by view name. Changing
                // namespace hints alone keeps cached legacy paths alive.
                View::getFinder()->flush();

                try {
                    // response()->view renders synchronously; restore the
                    // namespace in finally so subsequent requests (including
                    // feature tests / long-lived workers) keep the app theme.
                    return response()->view($view, $data, $statusCode);
                } finally {
                    $resolver->renderingLegacyPublicPage = false;
                    View::replaceNamespace('theme', $resolver->viewsPath());
                    View::getFinder()->flush();
                }
            }

            // Do not return a soft-404 (a 'coming soon' page with HTTP 200)
            // for URLs which search engines can already have indexed.
            if (in_array($name, self::LEGACY_PUBLIC_PAGES, true)) {
                abort(404);
            }

            $view = 'theme::pages.system.view_not_found';
            $data['page'] = $name;
            $data['view_error'] = "View for page '$name' not found.";
        }

        return response()->view($view, $data, $statusCode);
    }

    private static function normalizeStatusCode(mixed $statusCode): int
    {
        $statusCode = filter_var($statusCode, FILTER_VALIDATE_INT);

        return $statusCode >= 100 && $statusCode <= 599 ? $statusCode : 500;
    }

    public function viteInputs(): array
    {
        return [
            'resources/themes/'.$this->active().'/css/app.css',
            'resources/themes/'.$this->active().'/js/app.js',
        ];
    }
}
