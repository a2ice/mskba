<?php

namespace App\Filament\Backoffice\Resources\Venues\RelationManagers;

use App\Filament\Backoffice\Resources\Venues\VenueResource;
use App\Modules\Venue\Domain\Enums\VenueSurfaceTypeEnum;
use App\Modules\Venue\Domain\Models\VenueCourt;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class CourtsRelationManager extends RelationManager
{
    protected static string $relationship = 'courts';
    protected static ?string $title = 'Игровые зоны / залы';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return VenueResource::authorized() && ! $ownerRecord->trashed();
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Игровая зона')->columns(2)->schema([
                TextInput::make('name')->label('Название')->required()->maxLength(120),
                TextInput::make('alias')->label('URL-алиас')->required()->maxLength(120)
                    ->regex('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/D')
                    ->rule(fn (?VenueCourt $record) => Rule::unique('venue_courts', 'alias')
                        ->where('venue_id', $this->getOwnerRecord()->getKey())->ignore($record?->getKey())),
                TextInput::make('sort_order')->label('Порядок')->numeric()->minValue(0)->maxValue(65535)->default(10),
                Select::make('hoops_count')->label('Колец')->options([1 => '1', 2 => '2']),
                Select::make('surface_type')->label('Покрытие')->options(
                    collect(VenueSurfaceTypeEnum::cases())->mapWithKeys(fn ($case): array =>
                        [$case->value => $case->label()])->all()
                ),
                Toggle::make('supports_halves')->label('Разделяется на половины')->default(false),
                Toggle::make('allows_whole')->label('Бронирование целиком')->default(true),
                Toggle::make('allows_halves')->label('Бронирование половин')->default(false)
                    ->helperText('Включайте только для зоны с двумя кольцами и поддержкой половин.'),
            ]),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->label('Зал / зона')->searchable(),
            TextColumn::make('alias')->label('Код'),
            IconColumn::make('is_primary')->label('Основная')->boolean(),
            TextColumn::make('hoops_count')->label('Колец'),
            TextColumn::make('surface_type')->label('Покрытие')
                ->formatStateUsing(fn ($state): string => $state instanceof VenueSurfaceTypeEnum
                    ? $state->label() : (VenueSurfaceTypeEnum::tryFrom((string) $state)?->label() ?? '—')),
            IconColumn::make('allows_whole')->label('Целиком')->boolean(),
            IconColumn::make('allows_halves')->label('Половины')->boolean(),
        ])->headerActions([
            CreateAction::make()->label('Добавить зал')
                ->visible(fn (): bool => VenueResource::authorized()),
        ])->recordActions([
            EditAction::make(),
            Action::make('makePrimary')->label('Сделать основной')->icon('heroicon-o-star')
                ->visible(fn (VenueCourt $record): bool => ! $record->is_primary)
                ->requiresConfirmation()
                ->action(function (VenueCourt $record): void {
                    abort_unless(VenueResource::authorized(), 403);
                    DB::transaction(function () use ($record): void {
                        $ownerId = $this->getOwnerRecord()->getKey();
                        VenueCourt::query()->where('venue_id', $ownerId)->lockForUpdate()->get();
                        $court = VenueCourt::query()->where('venue_id', $ownerId)
                            ->whereKey($record->getKey())->firstOrFail();
                        VenueCourt::query()->where('venue_id', $ownerId)->where('is_primary', true)
                            ->update(['is_primary' => false]);
                        $court->update(['is_primary' => true]);
                    });
                }),
            DeleteAction::make()->requiresConfirmation()
                ->visible(fn (VenueCourt $record): bool => ! $record->is_primary
                    && ! $this->hasFutureUsage($record))
                ->before(function (VenueCourt $record): void {
                    abort_unless(VenueResource::authorized(), 403);
                    abort_if($record->is_primary || $this->hasFutureUsage($record), 422,
                        'Основной зал или зал с будущими бронированиями/мероприятиями нельзя удалить.');
                }),
        ])->defaultSort('sort_order');
    }

    private function hasFutureUsage(VenueCourt $court): bool
    {
        return DB::table('venue_bookings')->where('venue_court_id', $court->getKey())
            ->where('ends_at', '>', now())->exists()
            || DB::table('venue_booking_quotes')->where('venue_court_id', $court->getKey())
                ->where('starts_at', '>', now())->exists()
            || DB::table('events')->where('venue_court_id', $court->getKey())
                ->where('starts_at', '>', now())->exists();
    }
}
