<?php
namespace App\Filament\Backoffice\Resources\PageBuilder\Pages;

use App\Filament\Backoffice\Resources\PageBuilder\BuilderPageResource;
use Filament\Resources\Pages\CreateRecord;

final class CreateBuilderPage extends CreateRecord
{
    protected static string $resource = BuilderPageResource::class;
}
