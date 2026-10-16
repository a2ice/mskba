<?php
namespace App\Filament\Backoffice\Resources\PageBuilder\Pages;

use App\Filament\Backoffice\Resources\PageBuilder\Concerns\BuilderEditorActions;
use App\Filament\Backoffice\Resources\PageBuilder\BuilderComponentResource;
use Filament\Resources\Pages\EditRecord;

final class EditBuilderComponent extends EditRecord
{
    use BuilderEditorActions;

    protected static string $resource = BuilderComponentResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        if ($this->record?->slug !== 'hero-slider' || ! is_array($data['draft_slides'] ?? null)) {
            return $data;
        }

        // Existing JSON slides predate the "enabled" flag. Filament toggles
        // otherwise display those slides as disabled and could write false
        // back to a draft when the editor saves an unrelated change.
        foreach ($data['draft_slides'] as &$slide) {
            if (is_array($slide)) {
                $slide['layout'] ??= 'legacy';
                $slide['enabled'] ??= true;
            }
        }
        unset($slide);

        return $data;
    }
}
