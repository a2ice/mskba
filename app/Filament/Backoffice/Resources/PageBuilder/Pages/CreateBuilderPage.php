<?php
namespace App\Filament\Backoffice\Resources\PageBuilder\Pages;

use App\Filament\Backoffice\Resources\PageBuilder\BuilderPageResource;
use Filament\Resources\Pages\CreateRecord;
use App\Filament\Backoffice\Resources\PageBuilder\Concerns\BuilderCodeFormGuard;

final class CreateBuilderPage extends CreateRecord
{
    use BuilderCodeFormGuard;

    protected static string $resource = BuilderPageResource::class;
}
