<?php
namespace App\Filament\Backoffice\Resources\ManagerSpecializations\Pages;
use App\Filament\Backoffice\Resources\ManagerSpecializations\ManagerSpecializationResource;
use Filament\Resources\Pages\EditRecord;
final class EditManagerSpecialization extends EditRecord {
    protected static string $resource = ManagerSpecializationResource::class;
    protected function mutateFormDataBeforeSave(array $data): array {
        ManagerSpecializationResource::validatePermissions($data);
        unset($data['code'], $data['is_system']);
        return $data;
    }
}