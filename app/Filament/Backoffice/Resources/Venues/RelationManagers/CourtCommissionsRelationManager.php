<?php

namespace App\Filament\Backoffice\Resources\Venues\RelationManagers;

use App\Filament\Backoffice\Resources\Venues\VenueResource;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Venue\Domain\Models\VenueCourtCommissionPolicy;
use App\Modules\VenueBooking\Application\Services\MinorAmountParser;
use App\Modules\VenueBooking\Application\Services\VenueCommissionManager;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

final class CourtCommissionsRelationManager extends RelationManager
{
    protected static string $relationship = 'courtCommissionPolicies';
    protected static ?string $title = 'Переопределения комиссии по залам';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        $user = auth('web')->user();

        return VenueResource::authorized() && ! $ownerRecord->trashed()
            && $user !== null && $user->isConfirmed()
            && $user->hasSystemRole(UserSystemRoleEnum::SUPERADMIN);
    }

    /** @return array<int,mixed> */
    private function fields(bool $showCourt = true): array
    {
        return [
            ...($showCourt ? [
                Select::make('venue_court_id')->label('Зал')->required()->searchable()
                    ->options(fn (): array => $this->getOwnerRecord()->courts()->pluck('name', 'id')->all()),
            ] : []),
            Select::make('mode')->label('Комиссия MSKBA')
                ->options([
                    'inherit' => 'Наследовать от площадки',
                    'none' => 'Не начислять (0%)',
                    'percent' => 'Процент',
                    'fixed' => 'Фиксированная сумма',
                ])
                ->default('inherit')->required(),
            TextInput::make('value_text')->label('Значение (% или руб.)')
                ->required()->default('0')->maxLength(32),
        ];
    }

    private function actor()
    {
        $user = auth('web')->user();
        abort_unless($user !== null && $user->isConfirmed()
            && $user->hasSystemRole(UserSystemRoleEnum::SUPERADMIN), 403);
        return $user;
    }

    /** @return array<string,mixed> */
    private function state(VenueCourtCommissionPolicy $policy): array
    {
        $mode = $policy->mode_override ?? 'inherit';
        return [
            'mode' => $mode,
            'value_text' => match ($mode) {
                'percent' => number_format((int) $policy->value_override / 100, 2, '.', ''),
                'fixed' => app(MinorAmountParser::class)->format((int) $policy->value_override, $policy->currency),
                default => '0',
            },
        ];
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('court.name')->label('Зал'),
                TextColumn::make('mode_override')->label('Режим')
                    ->formatStateUsing(fn ($state): string => match ($state) {
                        null => 'Наследовать',
                        'none' => 'Без комиссии',
                        'percent' => 'Процент',
                        'fixed' => 'Фиксированная',
                        default => (string) $state,
                    }),
                TextColumn::make('value_override')->label('Значение')
                    ->formatStateUsing(fn (VenueCourtCommissionPolicy $record): string =>
                        $this->state($record)['value_text']),
                TextColumn::make('version')->label('Версия'),
            ])
            ->emptyStateHeading('Переопределений для залов пока нет')
            ->emptyStateDescription('По умолчанию зал наследует комиссию площадки, а площадка — глобальную.')
            ->headerActions([
                Action::make('setCourtCommission')->label('Настроить зал')
                    ->icon('heroicon-o-adjustments-horizontal')
                    ->modalHeading('Комиссия MSKBA для зала')
                    ->modalWidth(Width::Large)
                    ->schema($this->fields())
                    ->action(function (array $data): void {
                        $venue = $this->getOwnerRecord();
                        app(VenueCommissionManager::class)->setCourt(
                            $venue, $venue->courts()->findOrFail((int) $data['venue_court_id']),
                            $this->actor(), $data['mode'], $data['value_text'],
                        );
                        Notification::make()->success()->title('Комиссия зала сохранена')->send();
                    }),
            ])
            ->recordActions([
                Action::make('editCourtCommission')->label('Изменить')
                    ->modalHeading(fn (VenueCourtCommissionPolicy $record): string =>
                        'Комиссия: '.($record->court?->name ?: 'зал'))
                    ->modalWidth(Width::Large)
                    ->schema($this->fields(false))
                    ->fillForm(fn (VenueCourtCommissionPolicy $record): array => $this->state($record))
                    ->action(function (VenueCourtCommissionPolicy $record, array $data): void {
                        $venue = $this->getOwnerRecord();
                        app(VenueCommissionManager::class)->setCourt(
                            $venue, $venue->courts()->findOrFail($record->venue_court_id),
                            $this->actor(), $data['mode'], $data['value_text'],
                        );
                        Notification::make()->success()->title('Комиссия зала обновлена')->send();
                    }),
            ]);
    }
}
