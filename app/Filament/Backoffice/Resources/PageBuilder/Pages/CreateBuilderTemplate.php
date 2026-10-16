<?php
namespace App\Filament\Backoffice\Resources\PageBuilder\Pages;

use App\Filament\Backoffice\Resources\PageBuilder\BuilderTemplateResource;
use Filament\Resources\Pages\CreateRecord;

final class CreateBuilderTemplate extends CreateRecord
{
    protected static string $resource = BuilderTemplateResource::class;
}
