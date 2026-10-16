<?php
namespace App\Filament\Backoffice\Resources\PageBuilder\Pages;

use App\Filament\Backoffice\Resources\PageBuilder\Concerns\BuilderEditorActions;
use App\Filament\Backoffice\Resources\PageBuilder\BuilderPageResource;
use Filament\Resources\Pages\EditRecord;

final class EditBuilderPage extends EditRecord
{
    use BuilderEditorActions;
    protected static string $resource = BuilderPageResource::class;
}
