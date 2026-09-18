<?php

namespace App\Modules\Template\Infrastructure\Providers;

use App\Modules\Template\Application\Contracts\DocumentRenderer;
use App\Modules\Template\Application\Contracts\TemplateRenderer;
use App\Modules\Template\Application\Services\BladeTemplateRenderer;
use App\Modules\Template\Infrastructure\Services\GotenbergDocumentRenderer;
use Illuminate\Support\ServiceProvider;

final class TemplateServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(TemplateRenderer::class, BladeTemplateRenderer::class);
        $this->app->bind(DocumentRenderer::class, GotenbergDocumentRenderer::class);
    }
}
