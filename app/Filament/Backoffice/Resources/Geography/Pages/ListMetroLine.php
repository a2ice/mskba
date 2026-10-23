<?php

namespace App\Filament\Backoffice\Resources\Geography\Pages;

use App\Filament\Backoffice\Resources\Geography\MetroLineResource;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use Database\Seeders\MoscowMetroSeeder;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

final class ListMetroLine extends ListRecords
{
    protected static string $resource = MetroLineResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
            Action::make('seedMoscowMetro')->label('Загрузить метро Москвы')
                ->icon('heroicon-o-arrow-down-tray')
                ->visible(fn (): bool => auth('web')->user()?->canonical()->system_role
                    === UserSystemRoleEnum::SUPERADMIN && MetroLineResource::canViewAny())
                ->requiresConfirmation()
                ->modalDescription('Повторный импорт стандартного набора из сидера (21 линия, 452 станции). Координаты и порядок существующих одноимённых записей обновятся.')
                ->action(function (): void {
                    abort_unless(auth('web')->user()?->canonical()->system_role
                        === UserSystemRoleEnum::SUPERADMIN && MetroLineResource::canViewAny(), 403);
                    app(MoscowMetroSeeder::class)->run();
                    Notification::make()->success()->title('Справочник метро Москвы синхронизирован')->send();
                }),
        ];
    }
}
