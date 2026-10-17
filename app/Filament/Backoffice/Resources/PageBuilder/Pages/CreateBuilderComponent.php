<?php
namespace App\Filament\Backoffice\Resources\PageBuilder\Pages;

use App\Filament\Backoffice\Resources\PageBuilder\BuilderComponentResource;
use Filament\Resources\Pages\CreateRecord;
use App\Filament\Backoffice\Resources\PageBuilder\Concerns\BuilderCodeFormGuard;

final class CreateBuilderComponent extends CreateRecord
{
    use BuilderCodeFormGuard;

    protected static string $resource = BuilderComponentResource::class;
}
