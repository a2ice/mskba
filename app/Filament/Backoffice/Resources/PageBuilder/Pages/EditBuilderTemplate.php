<?php
namespace App\Filament\Backoffice\Resources\PageBuilder\Pages;

use App\Filament\Backoffice\Resources\PageBuilder\Concerns\BuilderEditorActions;
use App\Filament\Backoffice\Resources\PageBuilder\BuilderTemplateResource;
use Filament\Resources\Pages\EditRecord;

final class EditBuilderTemplate extends EditRecord
{
    use BuilderEditorActions;
    protected static string $resource = BuilderTemplateResource::class;
}
