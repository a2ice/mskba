<?php

namespace App\Filament\Backoffice\Resources\Venues\RelationManagers;

use App\Filament\Backoffice\Resources\Venues\VenueResource;
use App\Modules\Identity\Domain\Enums\UserOperationalPermissionEnum as Permission;
use App\Modules\VenueIntegration\Application\Services\VenueIntegrationManager;
use App\Modules\VenueIntegration\Domain\Models\VenueIntegrationSource;
use App\Modules\VenueIntegration\Infrastructure\Adapters\FindSportReadOnlyAdapter;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
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
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;

final class VenueIntegrationSourcesRelationManager extends RelationManager
{
    protected static string $relationship = 'integrationSources';
    protected static ?string $title = 'Интеграции: источники и залы';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        $actor = auth('web')->user();

        return VenueResource::authorized() && ! $ownerRecord->trashed()
            && $actor !== null && app(VenueIntegrationManager::class)->can(
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

    private function canExecute(): bool
    {
        $actor = auth('web')->user();

        return $actor !== null && app(VenueIntegrationManager::class)->can(
            $actor, Permission::BACKOFFICE_VENUE_INTEGRATIONS_EXECUTE,
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
            TextInput::make('name')->label('Название источника')->required()->maxLength(120),
            Select::make('adapter_key')->label('Тип адаптера')->required()
                ->options(['manual' => 'Ручной источник', 'api' => 'API', 'browser' => 'Browser Agent'])
                ->default('manual'),
            TextInput::make('external_id')->label('Внешний ID')->maxLength(190),
            TextInput::make('endpoint_url')->label('HTTPS-адрес источника')
                ->maxLength(2048)->helperText('Внешние вызовы пока отключены. Секреты в URL запрещены.'),
            TextInput::make('priority')->label('Приоритет')->numeric()->integer()
                ->minValue(0)->maxValue(1000)->default(100)->required(),
            CheckboxList::make('capabilities')->label('Данные источника')
                ->options(['availability' => 'Занятость', 'pricing' => 'Цены'])
                ->columns(2)->default([]),
            CheckboxList::make('court_ids')->label('Привязка к залам MSKBA')
                ->options(fn (): array => $this->getOwnerRecord()->courts()
                    ->pluck('name', 'id')->all())
                ->required()->columns(2),
            Select::make('permission_status')->label('Статус допустимого доступа')
                ->options([
                    'unchecked' => 'Не проверено',
                    'mskba_allowed' => 'Разрешено MSKBA (по проверенному основанию)',
                    'testing_only' => 'Только тестирование',
                    'prohibited' => 'Запрещено',
                ])
                ->default('unchecked')->required(),
            Textarea::make('permission_reference')->label('Основание допустимого доступа')
                ->maxLength(2000)->helperText('Контакт, договор, публичные условия. Сам статус не заменяет проверку ToS.'),
            TextInput::make('credentials_token')->label('Токен / ключ доступа (зашифрован)')
                ->password()->revealable(false)->maxLength(8000)
                ->helperText('Оставьте пустым, чтобы не менять существующий ключ. Он никогда не отображается обратно.'),
            Toggle::make('enabled')->label('Активировать источник')->default(false)
                ->helperText('Внешние API/Browser-источники блокируются общим kill switch до задачи 306.'),
        ];
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    private function sourceData(array $data): array
    {
        $token = trim((string) ($data['credentials_token'] ?? ''));
        unset($data['credentials_token']);
        if ($token !== '') {
            $data['credentials'] = ['token' => $token];
        }

        return $data;
    }

    /** @return array<string,mixed> */
    private function state(VenueIntegrationSource $source): array
    {
        return [
            'name' => $source->name,
            'adapter_key' => $source->adapter_key,
            'external_id' => $source->external_id,
            'endpoint_url' => $source->endpoint_url,
            'priority' => $source->priority,
            'capabilities' => $source->capabilities ?? [],
            'court_ids' => $source->courtMappings()->pluck('venue_court_id')->all(),
            'permission_status' => $source->permission_status,
            'permission_reference' => $source->permission_reference,
            'credentials_token' => '', // never decrypt and show a stored key
            'enabled' => $source->enabled,
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('Источник')->searchable(),
                TextColumn::make('adapter_key')->label('Адаптер'),
                TextColumn::make('permission_status')->label('Доступ')->badge(),
                IconColumn::make('enabled')->label('Включён')->boolean(),
                TextColumn::make('priority')->label('Приоритет'),
                TextColumn::make('version')->label('Версия'),
            ])
            ->emptyStateHeading('Источников нет')
            ->emptyStateDescription('Сначала создайте конфигурацию выше, затем добавьте источники и свяжите их с залами.')
            ->headerActions([
                Action::make('createIntegrationSource')->label('Добавить источник')
                    ->icon('heroicon-o-plus')
                    ->visible(fn (): bool => $this->canManage()
                        && $this->getOwnerRecord()->integrations()->exists())
                    ->modalHeading('Новый источник расписания или цен')
                    ->schema($this->fields())
                    ->action(function (array $data): void {
                        abort_unless($this->canManage(), 403);
                        $config = $this->getOwnerRecord()->integrations()->firstOrFail();
                        app(VenueIntegrationManager::class)->saveSource(
                            $config, auth('web')->user(), $this->sourceData($data),
                        );
                        Notification::make()->success()->title('Источник сохранён как черновик')->send();
                    }),
            ])
            ->recordActions([
                Action::make('previewFindSport')->label('Получить данные')
                    ->icon('heroicon-o-eye')
                    ->visible(fn (VenueIntegrationSource $record): bool => $this->canExecute()
                        && $record->adapter_key === 'api'
                        && str_starts_with((string) $record->endpoint_url, 'https://findsport.ru/playground/'))
                    ->modalHeading('FindSport — read-only предпросмотр')
                    ->modalWidth('7xl')
                    ->modalSubmitAction(false)
                    ->modalContent(function (VenueIntegrationSource $record): View {
                        abort_unless($this->canExecute(), 403);

                        try {
                            $report = app(FindSportReadOnlyAdapter::class)->preview(
                                $record, auth('web')->user(),
                            );
                            $error = null;
                        } catch (\Throwable $exception) {
                            report($exception);
                            $report = null;
                            $error = $exception->getMessage();
                        }

                        return view(
                            'filament.backoffice.venue-integrations.findsport-preview',
                            compact('report', 'error'),
                        );
                    }),
                Action::make('editIntegrationSource')->label('Изменить')
                    ->icon('heroicon-o-pencil-square')
                    ->visible(fn (): bool => $this->canManage())
                    ->modalHeading('Настройки источника')
                    ->schema($this->fields())
                    ->fillForm(fn (VenueIntegrationSource $record): array => $this->state($record))
                    ->action(function (VenueIntegrationSource $record, array $data): void {
                        abort_unless($this->canManage(), 403);
                        $config = $this->getOwnerRecord()->integrations()->firstOrFail();
                        app(VenueIntegrationManager::class)->saveSource(
                            $config, auth('web')->user(), $this->sourceData($data), $record,
                        );
                        Notification::make()->success()->title('Источник обновлён')->send();
                    }),
            ]);
    }
}
