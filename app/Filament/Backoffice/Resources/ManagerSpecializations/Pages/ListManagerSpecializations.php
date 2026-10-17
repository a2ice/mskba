<?php
namespace App\Filament\Backoffice\Resources\ManagerSpecializations\Pages;
use App\Filament\Backoffice\Resources\ManagerSpecializations\ManagerSpecializationResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
final class ListManagerSpecializations extends ListRecords {
    protected static string $resource = ManagerSpecializationResource::class;
    protected function getHeaderActions(): array { return [CreateAction::make()]; }
}