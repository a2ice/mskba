<?php

namespace App\Filament\Backoffice\Resources\LeagueSeasons;

use App\Filament\Backoffice\Resources\Competitions\CompetitionCatalogEditor;
use App\Filament\Backoffice\Resources\LeagueSeasons\Pages\CreateLeagueSeason;
use App\Filament\Backoffice\Resources\LeagueSeasons\Pages\EditLeagueSeason;
use App\Filament\Backoffice\Resources\LeagueSeasons\Pages\ListLeagueSeasons;
use App\Modules\Identity\Application\Services\BackofficePermissionManager;
use App\Modules\Identity\Domain\Enums\UserOperationalPermissionEnum as Permission;
use App\Modules\Tournament\Domain\Models\League;
use App\Modules\Tournament\Domain\Models\LeagueSeason;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

final class LeagueSeasonResource extends Resource
{
    protected static ?string $model = LeagueSeason::class;
    protected static ?string $recordTitleAttribute = 'name';
    protected static string|\UnitEnum|null $navigationGroup = 'Контент';
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-calendar';
    protected static ?int $navigationSort = 6;

    public static function getNavigationLabel(): string { return 'Сезоны'; }
    public static function getModelLabel(): string { return 'сезон'; }
    public static function getPluralModelLabel(): string { return 'сезоны'; }

    private static function authorized(): bool
    {
        $user = auth('web')->user();
        return $user !== null
            && app(BackofficePermissionManager::class)->allows($user, Permission::BACKOFFICE_ACCESS)
            && app(BackofficePermissionManager::class)->allows($user, Permission::BACKOFFICE_SEASON_MANAGE);
    }

    public static function canViewAny(): bool { return self::authorized(); }
    public static function canView(Model $record): bool { return $record instanceof LeagueSeason && self::authorized(); }
    public static function canCreate(): bool { return self::authorized(); }
    public static function canEdit(Model $record): bool { return self::canView($record) && ! $record->trashed(); }
    public static function canDelete(Model $record): bool { return self::canEdit($record); }
    public static function canRestore(Model $record): bool { return self::canView($record) && $record->trashed(); }
    public static function canDeleteAny(): bool { return false; }
    public static function canForceDelete(Model $record): bool { return false; }
    public static function canForceDeleteAny(): bool { return false; }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make('Сезон лиги')->description('Даты необязательны: сезон может идти без фиксированного периода.')
                ->columns(2)->schema([
                    Select::make('league_id')->label('Лига')->required()->searchable()->preload()
                        ->options(fn (?LeagueSeason $record): array => League::query()
                            ->where(function ($q) use ($record) {
                                $q->where('status', 'active');
                                if ($record !== null) $q->orWhereKey($record->league_id);
                            })
                            ->orderBy('name')->pluck('name', 'id')->all())
                        ->columnSpanFull(),
                    TextInput::make('name')->label('Название')->required()->minLength(2)->maxLength(160),
                    TextInput::make('slug')->label('Адресный идентификатор')->maxLength(180)
                        ->helperText('Если оставить пустым, сформируется из названия.'),
                    DatePicker::make('starts_on')->label('Начало')->native(false),
                    DatePicker::make('ends_on')->label('Окончание')->native(false),
                    Select::make('status')->label('Статус')->required()
                        ->options(['planned' => 'Запланирован', 'active' => 'Идёт', 'completed' => 'Завершён', 'archived' => 'В архиве'])
                        ->default('planned'),
                    Textarea::make('description')->label('Описание')->rows(4)->maxLength(10000)->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('id')->label('ID')->sortable(),
            TextColumn::make('name')->label('Сезон')->searchable()->sortable()->weight('bold'),
            TextColumn::make('league.name')->label('Лига')->searchable()->sortable(),
            TextColumn::make('starts_on')->label('Начало')->date('d.m.Y')->placeholder('Без даты'),
            TextColumn::make('ends_on')->label('Окончание')->date('d.m.Y')->placeholder('Без даты'),
            TextColumn::make('status')->label('Статус')->badge(),
            TextColumn::make('tournaments_count')->label('Турниров')->counts('tournaments'),
        ])->defaultSort('id', 'desc')
        ->filters([
            SelectFilter::make('league_id')->label('Лига')->options(fn (): array => League::query()->orderBy('name')->pluck('name', 'id')->all()),
            SelectFilter::make('status')->label('Статус')
                ->options(['planned' => 'Запланирован', 'active' => 'Идёт', 'completed' => 'Завершён', 'archived' => 'В архиве']),
            TrashedFilter::make(),
        ])
        ->recordActions([
            EditAction::make()->label('Редактировать'),
            Action::make('deleteSeason')->label('Удалить')->color('danger')->icon('heroicon-o-trash')
                ->requiresConfirmation()->visible(fn (LeagueSeason $record): bool => self::canDelete($record))
                ->action(function (LeagueSeason $record): void {
                    app(CompetitionCatalogEditor::class)->deleteSeason($record);
                    Notification::make()->success()->title('Сезон удалён')->send();
                }),
            Action::make('restoreSeason')->label('Восстановить')->icon('heroicon-o-arrow-uturn-left')
                ->requiresConfirmation()->visible(fn (LeagueSeason $record): bool => self::canRestore($record))
                ->action(function (LeagueSeason $record): void {
                    app(CompetitionCatalogEditor::class)->restoreSeason($record);
                    Notification::make()->success()->title('Сезон восстановлен')->send();
                }),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLeagueSeasons::route('/'),
            'create' => CreateLeagueSeason::route('/create'),
            'edit' => EditLeagueSeason::route('/{record}/edit'),
        ];
    }
}
