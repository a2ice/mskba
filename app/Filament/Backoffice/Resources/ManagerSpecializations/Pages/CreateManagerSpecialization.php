<?php
namespace App\Filament\Backoffice\Resources\ManagerSpecializations\Pages;
use App\Filament\Backoffice\Resources\ManagerSpecializations\ManagerSpecializationResource;
use Filament\Resources\Pages\CreateRecord;
final class CreateManagerSpecialization extends CreateRecord {
    protected static string $resource = ManagerSpecializationResource::class;
    protected function mutateFormDataBeforeCreate(array $data): array {
        ManagerSpecializationResource::validatePermissions($data);
        $data['is_system'] = false;
        return $data;
    }
}