<?php
namespace App\Filament\Backoffice\Resources\PageBuilder\Pages;

use App\Filament\Backoffice\Resources\PageBuilder\Concerns\BuilderEditorActions;
use App\Filament\Backoffice\Resources\PageBuilder\BuilderComponentResource;
use Filament\Resources\Pages\EditRecord;

final class EditBuilderComponent extends EditRecord
{
    use BuilderEditorActions;
    protected static string $resource = BuilderComponentResource::class;
}
