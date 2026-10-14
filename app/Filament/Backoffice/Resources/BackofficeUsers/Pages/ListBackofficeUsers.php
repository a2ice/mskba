<?php

namespace App\Filament\Backoffice\Resources\BackofficeUsers\Pages;

use App\Filament\Backoffice\Resources\BackofficeUsers\BackofficeUserResource;
use Filament\Resources\Pages\ListRecords;

final class ListBackofficeUsers extends ListRecords
{
    protected static string $resource = BackofficeUserResource::class;
}
