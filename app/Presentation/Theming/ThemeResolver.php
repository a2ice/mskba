<?php

namespace app\Presentation\Theming;

class ThemeResolver
{
    public function active(): string
    {
        $theme = config('themes.active', 'mskba_dark');

        return is_string($theme) && $theme !== '' ? $theme : 'mskba_dark';
    }

    public function name(): string
    {
        $name = config('themes.items.'.$this->active().'.name');

        return is_string($name) && $name !== '' ? $name : $this->active();
    }

    public function viewsPath(): string
    {
        $path = config('themes.items.'.$this->active().'.views');

        return is_string($path) && $path !== ''
            ? $path
            : resource_path('themes/'.$this->active().'/views');
    }

    public function view(string $name): string
    {
        return 'theme::'.$name;
    }

    public function viteInputs(): array
    {
        $inputs = config('themes.items.'.$this->active().'.assets', []);

        return is_array($inputs) ? $inputs : [];
    }
}
