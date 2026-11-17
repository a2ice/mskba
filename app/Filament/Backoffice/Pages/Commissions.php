<?php

namespace App\Filament\Backoffice\Pages;

use App\Filament\Backoffice\Resources\Venues\VenueResource;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Domain\Models\PortalCommissionPolicy;
use App\Modules\VenueBooking\Application\Services\PortalCommissionScopes;
use App\Modules\VenueBooking\Application\Services\VenueCommissionManager;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;

final class Commissions extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $slug = 'commissions';
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-receipt-percent';
    protected static string|\UnitEnum|null $navigationGroup = 'Контент';
    protected static ?int $navigationSort = 3;
    protected static ?string $navigationLabel = 'Комиссии';
    protected string $view = 'filament.backoffice.pages.commissions';

    public static function canAccess(): bool
    {
        $user = auth('web')->user();

        return VenueResource::authorized() && $user instanceof User
            && $user->isConfirmed() && $user->hasSystemRole(UserSystemRoleEnum::SUPERADMIN);
    }

    public function mount(): void
    {
        abort_unless(self::canAccess(), 403);

        // An entry with NULL mode explicitly means "inherit the common rate".
        foreach (PortalCommissionScopes::catalog() as $scope) {
            PortalCommissionPolicy::query()->firstOrCreate(
                ['entity_type' => $scope['entity_type'], 'action_key' => $scope['action_key']],
                ['mode' => null, 'value' => null, 'currency' => 'RUB', 'version' => 0],
            );
        }
    }

    public function getTitle(): string
    {
        return 'Комиссии';
    }

    private function currentPolicy(): ?PortalCommissionPolicy
    {
        return PortalCommissionPolicy::query()
            ->where('entity_type', PortalCommissionScopes::GLOBAL_ENTITY)
            ->where('action_key', PortalCommissionScopes::GLOBAL_ACTION)->first();
    }

    public function globalRate(): string
    {
        return PortalCommissionScopes::format($this->currentPolicy());
    }

    public function globalVersion(): int
    {
        return $this->currentPolicy()?->version ?? 0;
    }

    /** @return array<int, mixed> */
    private function rateFields(): array
    {
        return [
            Select::make('mode')->label('Способ расчёта')->required()
                ->options(['percent' => 'Процент (включая 0%)', 'fixed' => 'Фиксированная сумма']),
            TextInput::make('value_text')->label('Значение (% или руб.)')
                ->required()->maxLength(32),
        ];
    }

    /** @return array{mode:string,value_text:string} */
    private function rateState(?PortalCommissionPolicy $policy): array
    {
        $mode = $policy?->mode === 'fixed' ? 'fixed' : 'percent';
        return [
            'mode' => $mode,
            'value_text' => number_format(($policy?->value ?? 0) / 100, 2, '.', ''),
        ];
    }

    private function actor(): User
    {
        $user = auth('web')->user();
        abort_unless($user instanceof User && self::canAccess(), 403);
        return $user;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('editGlobalCommission')
                ->label('Изменить общую ставку')
                ->icon('heroicon-o-pencil-square')
                ->modalHeading('Общая комиссия MSKBA')
                ->modalDescription('Применяется ко всем сущностям и действиям, у которых нет собственной ставки.')
                ->modalWidth(Width::Large)
                ->schema($this->rateFields())
                ->fillForm(fn (): array => $this->rateState($this->currentPolicy()))
                ->action(function (array $data): void {
                    app(VenueCommissionManager::class)->setPortal(
                        $this->actor(), $data['mode'], $data['value_text'],
                    );
                    Notification::make()->success()->title('Общая комиссия сохранена')->send();
                }),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(PortalCommissionPolicy::query()
                ->where('entity_type', '!=', PortalCommissionScopes::GLOBAL_ENTITY))
            ->heading('Комиссии по сущностям и действиям')
            ->description('Без собственной ставки наследуется общая. Явные 0% не являются наследованием.')
            ->columns([
                TextColumn::make('entity_type')->label('Сущность')
                    ->formatStateUsing(fn (PortalCommissionPolicy $record): string =>
                        $this->scopeLabel($record, 'entity_label')),
                TextColumn::make('action_key')->label('Действие')
                    ->formatStateUsing(fn (PortalCommissionPolicy $record): string =>
                        $this->scopeLabel($record, 'action_label')),
                TextColumn::make('mode')->label('Настройка')
                    ->state(fn (PortalCommissionPolicy $record): string => $record->mode ?? 'inherit')
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'inherit' => 'Наследование',
                        'percent' => 'Собственная · %',
                        'fixed' => 'Собственная · ₽',
                        default => '0%',
                    }),
                TextColumn::make('effective_rate')->label('Действующая комиссия')
                    ->state(fn (PortalCommissionPolicy $record): string =>
                        PortalCommissionScopes::format(
                            PortalCommissionScopes::resolve($record->entity_type, $record->action_key)['policy'],
                        )),
                TextColumn::make('connected')->label('Применение')
                    ->state(fn (PortalCommissionPolicy $record): string =>
                        $this->scopeConnected($record) ? 'В расчёте' : 'Подготовлено'),
                TextColumn::make('version')->label('Версия')->numeric(),
            ])
            ->recordActions([
                Action::make('editScopedCommission')
                    ->label('Изменить')
                    ->icon('heroicon-o-pencil-square')
                    ->modalHeading(fn (PortalCommissionPolicy $record): string =>
                        $this->scopeLabel($record, 'entity_label').' / '.$this->scopeLabel($record, 'action_label'))
                    ->modalWidth(Width::Large)
                    ->schema([
                        Toggle::make('has_override')->label('Использовать собственную ставку')
                            ->live()->default(false)
                            ->helperText('Выключено = NULL (наследуется общая). Включено с 0 = нулевая комиссия.'),
                        Select::make('mode')->label('Способ расчёта')
                            ->options(['percent' => 'Процент (включая 0%)', 'fixed' => 'Фиксированная сумма'])
                            ->default('percent')->required()
                            ->disabled(fn (Get $get): bool => ! $get('has_override')),
                        TextInput::make('value_text')->label('Значение (% или руб.)')
                            ->default('0')->required()->maxLength(32)
                            ->disabled(fn (Get $get): bool => ! $get('has_override')),
                    ])
                    ->fillForm(fn (PortalCommissionPolicy $record): array => [
                        'has_override' => $record->mode !== null,
                        ...$this->rateState($record),
                    ])
                    ->action(function (PortalCommissionPolicy $record, array $data): void {
                        $own = (bool) ($data['has_override'] ?? false);
                        app(VenueCommissionManager::class)->setScoped(
                            $this->actor(), $record->entity_type, $record->action_key,
                            $own ? $data['mode'] : 'inherit',
                            $own ? $data['value_text'] : '0',
                        );
                        Notification::make()->success()->title('Комиссия сохранена')->send();
                    }),
            ])
            ->paginated(false);
    }

    private function scopeLabel(PortalCommissionPolicy $record, string $key): string
    {
        foreach (PortalCommissionScopes::catalog() as $scope) {
            if ($scope['entity_type'] === $record->entity_type
                && $scope['action_key'] === $record->action_key) {
                return $scope[$key];
            }
        }
        return $key === 'entity_label' ? $record->entity_type : $record->action_key;
    }

    private function scopeConnected(PortalCommissionPolicy $record): bool
    {
        foreach (PortalCommissionScopes::catalog() as $scope) {
            if ($scope['entity_type'] === $record->entity_type
                && $scope['action_key'] === $record->action_key) {
                return $scope['connected'];
            }
        }
        return false;
    }
}
