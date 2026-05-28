<?php

namespace App\Presentation\Theming;

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

    public function viteInputs(): array
    {
        return [
            'resources/themes/'.$this->active().'/css/app.css',
            'resources/themes/'.$this->active().'/js/app.js',
        ];
    }
}
