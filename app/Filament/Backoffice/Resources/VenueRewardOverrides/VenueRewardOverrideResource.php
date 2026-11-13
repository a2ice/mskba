<?php

namespace App\Filament\Backoffice\Resources\VenueRewardOverrides;

use App\Filament\Backoffice\Resources\VenueRewardOverrides\Pages\ListVenueRewardOverrides;
use App\Filament\Backoffice\Resources\VenueRewardOverrides\Pages\CreateVenueRewardOverride;
use App\Filament\Backoffice\Resources\VenueRewardOverrides\Pages\EditVenueRewardOverride;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Rewards\Domain\Models\VenueRewardOverride;
use App\Modules\Venue\Domain\Models\Venue;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

final class VenueRewardOverrideResource extends Resource
{
    protected static ?string $model = VenueRewardOverride::class;
    protected static string|\UnitEnum|null $navigationGroup = 'Контент';
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-building-office';
    protected static ?int $navigationSort = 11;

    // Cashback overrides are managed in Venues → Edit → Cashback.
    // Keep existing URLs accessible for bookmarks, but avoid a duplicate menu.
    public static function shouldRegisterNavigation(): bool { return false; }

    public static function getNavigationLabel(): string { return 'Кешбэк по площадкам'; }
    public static function getModelLabel(): string { return 'настройка кешбэка'; }
    public static function getPluralModelLabel(): string { return 'Кешбэк по площадкам'; }

    private static function superadmin(): bool
    {
        $user = auth('web')->user();

        return $user !== null
            && $user->canonical()->isConfirmed()
            && ! $user->canonical()->isBlocked()
            && $user->canonical()->system_role === UserSystemRoleEnum::SUPERADMIN;
    }

    public static function canViewAny(): bool { return self::superadmin(); }
    public static function canCreate(): bool { return self::superadmin(); }
    public static function canEdit(Model $record): bool { return self::superadmin(); }
    public static function canDelete(Model $record): bool { return self::superadmin(); }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Переопределение кешбэка для площадки')
                ->description('По умолчанию площадка наследует глобальный фиксированный номинал и статус из Rewards. Глобальное отключение сильнее локальных настроек.')
                ->columns(2)
                ->schema([
                    Select::make('venue_id')->label('Площадка')
                        ->options(fn (): array => Venue::query()->orderBy('name')->pluck('name', 'id')->all())
                        ->searchable()->required()->disabledOn('edit')->native(false),
                    Select::make('mode')->label('Режим')
                        ->options([
                            'inherit' => 'Наследовать глобальный',
                            'disabled' => 'Отключить для площадки',
                            'custom' => 'Своя фиксированная сумма',
                        ])
                        ->default('inherit')->required()->live()->native(false),
                    TextInput::make('amount_rub')->label('Своя сумма, бонусных ₽')
                        ->numeric()->step('0.01')->minValue(0)->maxValue(10000000)
                        ->required(fn (Get $get): bool => $get('mode') === 'custom')
                        ->visible(fn (Get $get): bool => $get('mode') === 'custom')
                        ->helperText('0 — именно ноль; нулевая сумма отключает выплату и рекламное обещание.'),
                ]),
        ]);
    }

    public static function prepareData(array $data, ?VenueRewardOverride $record = null): array
    {
        $reward = \App\Modules\Rewards\Domain\Models\Reward::query()
            ->where('code', 'venue_rental_completed')->first();

        if ($reward === null) {
            throw ValidationException::withMessages(['data.venue_id' => 'Сначала создайте вознаграждение за аренду.']);
        }

        $data['reward_id'] = $reward->id;
        $mode = $data['mode'] ?? 'inherit';
        if (! in_array($mode, ['inherit', 'disabled', 'custom'], true)) {
            throw ValidationException::withMessages(['data.mode' => 'Недопустимый режим.']);
        }

        if ($mode === 'custom') {
            $value = trim((string) ($data['amount_rub'] ?? ''));
            if (! preg_match('/\A\d+(?:\.\d{1,2})?\z/D', $value)) {
                throw ValidationException::withMessages(['data.amount_rub' => 'Укажите сумму до двух знаков после точки.']);
            }
            [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
            $data['amount_minor'] = ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
        } else {
            $data['amount_minor'] = null;
        }

        unset($data['amount_rub']);

        if ($record === null && VenueRewardOverride::query()
                ->where('reward_id', $reward->id)->where('venue_id', (int) $data['venue_id'])->exists()) {
            throw ValidationException::withMessages(['data.venue_id' => 'Для этой площадки настройка уже существует.']);
        }

        return $data;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('venue.name')->label('Площадка')->searchable(),
                TextColumn::make('mode')->label('Режим')
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'custom' => 'Своя сумма',
                        'disabled' => 'Отключено',
                        default => 'Наследование',
                    }),
                TextColumn::make('amount_minor')->label('Своя сумма')
                    ->formatStateUsing(fn (?int $state): string => $state === null ? '—' : number_format($state / 100, 2, ',', ' ').' ₽'),
            ])->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListVenueRewardOverrides::route('/'),
            'create' => CreateVenueRewardOverride::route('/create'),
            'edit' => EditVenueRewardOverride::route('/{record}/edit'),
        ];
    }
}
