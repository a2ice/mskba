<?php
namespace App\Filament\Backoffice\Resources\PageBuilder\Pages;

use App\Filament\Backoffice\Resources\PageBuilder\BuilderTemplateResource;
use Filament\Resources\Pages\CreateRecord;
use App\Filament\Backoffice\Resources\PageBuilder\Concerns\BuilderCodeFormGuard;

final class CreateBuilderTemplate extends CreateRecord
{
    use BuilderCodeFormGuard;

    protected static string $resource = BuilderTemplateResource::class;
}
