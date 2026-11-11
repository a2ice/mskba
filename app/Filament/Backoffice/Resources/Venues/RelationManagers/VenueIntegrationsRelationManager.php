<?php

namespace App\Filament\Backoffice\Resources\Venues\RelationManagers;

use App\Filament\Backoffice\Resources\Venues\VenueResource;
use App\Modules\Identity\Domain\Enums\UserOperationalPermissionEnum as Permission;
use App\Modules\VenueIntegration\Application\Services\VenueIntegrationManager;
use App\Modules\VenueIntegration\Domain\Models\VenueIntegration;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

final class VenueIntegrationsRelationManager extends RelationManager
{
    protected static string $relationship = 'integrations';
    protected static ?string $title = 'Интеграции: настройки (Task 305)';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        $actor = auth('web')->user();

        return VenueResource::authorized() && ! $ownerRecord->trashed()
            && $actor !== null
            && app(VenueIntegrationManager::class)->can(
                $actor, Permission::BACKOFFICE_VENUE_INTEGRATIONS_VIEW,
            );
    }

    private function canManage(): bool
    {
        $actor = auth('web')->user();

        return $actor !== null && app(VenueIntegrationManager::class)->can(
            $actor, Permission::BACKOFFICE_VENUE_INTEGRATIONS_MANAGE,
        );
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    /** @return array<int,mixed> */
    private function fields(): array
    {
        return [
            TextInput::make('name')->label('Название')->required()->maxLength(120),
            Textarea::make('description')->label('Описание')->maxLength(4000),
            TextInput::make('timezone')->label('Часовой пояс')->required()->default('Europe/Moscow')
                ->helperText('Например Europe/Moscow'),
            TextInput::make('freshness_ttl_seconds')->label('Срок актуальности, сек')
                ->numeric()->integer()->required()->minValue(60)->maxValue(86400)->default(900),
            TextInput::make('horizon_days')->label('Горизонт, дней')
                ->numeric()->integer()->required()->minValue(1)->maxValue(90)->default(14),
            Select::make('mode')->label('Режим')->required()
                ->options(['test' => 'Тестирование', 'production' => 'Production'])
                ->default('test'),
            Toggle::make('refresh_on_visit')->label('Обновлять при посещении')->default(false)
                ->helperText('Пока только настройка: задачи 306–308 ещё не запускают запросы.'),
            Toggle::make('scheduled_refresh')->label('Обновлять по расписанию')->default(false)
                ->helperText('Планировщик появится в задаче 308.'),
            Toggle::make('enabled')->label('Включить конфигурацию')->default(false)
                ->helperText('Требуется хотя бы один разрешённый активный источник и привязка зала.'),
        ];
    }

    /** @return array<string,mixed> */
    private function state(VenueIntegration $record): array
    {
        return $record->only([
            'name', 'description', 'timezone', 'freshness_ttl_seconds',
            'horizon_days', 'mode', 'refresh_on_visit', 'scheduled_refresh', 'enabled',
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('Конфигурация'),
                TextColumn::make('mode')->label('Режим')
                    ->formatStateUsing(fn (string $state): string =>
                        $state === 'test' ? 'Тестирование' : 'Production'),
                TextColumn::make('timezone')->label('Часовой пояс'),
                IconColumn::make('enabled')->label('Включено')->boolean(),
                TextColumn::make('version')->label('Версия'),
            ])
            ->emptyStateHeading('Интеграции не настроены')
            ->emptyStateDescription('Сначала создайте выключенный черновик. Никакие внешние запросы не выполняются.')
            ->headerActions([
                Action::make('createVenueIntegration')->label('Настроить интеграцию')
                    ->icon('heroicon-o-plus')
                    ->visible(fn (): bool => $this->canManage()
                        && ! $this->getOwnerRecord()->integrations()->exists())
                    ->modalHeading('Интеграция площадки')
                    ->schema($this->fields())
                    ->action(function (array $data): void {
                        abort_unless($this->canManage(), 403);
                        app(VenueIntegrationManager::class)->saveConfiguration(
                            $this->getOwnerRecord(), auth('web')->user(), $data,
                        );
                        Notification::make()->success()->title('Конфигурация сохранена')->send();
                    }),
            ])
            ->recordActions([
                Action::make('editVenueIntegration')->label('Изменить')
                    ->icon('heroicon-o-pencil-square')
                    ->visible(fn (): bool => $this->canManage())
                    ->modalHeading('Настройки интеграции')
                    ->schema($this->fields())
                    ->fillForm(fn (VenueIntegration $record): array => $this->state($record))
                    ->action(function (VenueIntegration $record, array $data): void {
                        abort_unless($this->canManage(), 403);
                        app(VenueIntegrationManager::class)->saveConfiguration(
                            $this->getOwnerRecord(), auth('web')->user(), $data,
                        );
                        Notification::make()->success()->title('Настройки обновлены')->send();
                    }),
            ]);
    }
}
