<?php

namespace App\Presentation\Theming;
use Illuminate\View\View;
use Illuminate\Http\Response;

class ThemeResolver
{
    public function __construct(
        private readonly array $config,
    ) {}

    public function active(): string
    {
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
        if (!$viewExists) {
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
