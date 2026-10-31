<?php

namespace App\Filament\Backoffice\Resources\Seasons;

use App\Filament\Backoffice\Resources\Seasons\Pages\CreateSeason;
use App\Filament\Backoffice\Resources\Seasons\Pages\EditSeason;
use App\Filament\Backoffice\Resources\Seasons\Pages\ListSeasons;
use App\Modules\Identity\Application\Services\BackofficePermissionManager;
use App\Modules\Identity\Domain\Enums\UserOperationalPermissionEnum as Permission;
use App\Modules\League\Domain\Models\League;
use App\Modules\League\Domain\Models\LeagueSeason;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

final class SeasonResource extends Resource
{
    protected static ?string $model = LeagueSeason::class;

    // Seasons are managed from the related League; keep legacy routes for bookmarks.
    protected static bool $shouldRegisterNavigation = false;
    protected static ?string $recordTitleAttribute = 'name';
    protected static string|\UnitEnum|null $navigationGroup = 'Контент';
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-calendar-days';
    protected static ?int $navigationSort = 6;

    public static function getNavigationLabel(): string { return 'Сезоны'; }
    public static function getModelLabel(): string { return 'сезон'; }
    public static function getPluralModelLabel(): string { return 'сезоны'; }

    public static function allows(Permission $permission): bool
    {
        $user = auth('web')->user();
        return $user !== null
            && app(BackofficePermissionManager::class)->allows($user, Permission::BACKOFFICE_ACCESS)
            && app(BackofficePermissionManager::class)->allows($user, $permission);
    }

    public static function canViewAny(): bool { return self::allows(Permission::BACKOFFICE_SEASON_VIEW); }
    public static function canView(Model $record): bool { return $record instanceof LeagueSeason && self::canViewAny(); }
    public static function canCreate(): bool { return self::allows(Permission::BACKOFFICE_SEASON_CREATE); }
    public static function canEdit(Model $record): bool { return $record instanceof LeagueSeason && self::allows(Permission::BACKOFFICE_SEASON_EDIT); }
    public static function canDelete(Model $record): bool { return $record instanceof LeagueSeason && self::canArchive(); }
    public static function canArchive(): bool { return self::allows(Permission::BACKOFFICE_SEASON_DELETE); }
    public static function canManageTeams(): bool { return self::allows(Permission::BACKOFFICE_SEASON_TEAMS_MANAGE); }
    public static function canDeleteAny(): bool { return false; }
    public static function canForceDelete(Model $record): bool { return false; }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Tabs::make('Сезон')->persistTabInQueryString()->tabs([
                Tab::make('Баннер')->icon('heroicon-o-photo')
                    ->visible(fn ($record): bool => $record !== null && self::canEdit($record))
                    ->schema([View::make('filament.backoffice.entity-banner')]),

                Tab::make('Основное')->icon('heroicon-o-calendar')->schema([
                    Section::make('Информация о сезоне')->columns(2)->schema([
                        Select::make('league_id')->label('Лига')->required()
                            ->options(fn (): array => League::query()->where('status', '!=', 'archived')
                                ->orderBy('name')->pluck('name', 'id')->all())
                            ->searchable()->visible(fn (?LeagueSeason $record): bool => $record === null),
                        TextInput::make('name')->label('Название')->required()->minLength(2)->maxLength(150),
                        Select::make('status')->label('Статус')->required()
                            ->options(['draft'=>'Черновик','active'=>'Активный','archived'=>'В архиве'])->default('draft'),
                        DatePicker::make('starts_on')->label('Начало')->native(false),
                        DatePicker::make('ends_on')->label('Окончание')->native(false)->afterOrEqual('starts_on'),
                        Textarea::make('description')->label('Описание')->rows(4)->columnSpanFull(),
                    ]),
                ]),
                Tab::make('Команды')->icon('heroicon-o-user-group')
                    ->visible(fn (?LeagueSeason $record): bool => $record !== null && self::canManageTeams())
                    ->schema([
                        Section::make('Команды сезона')->description('Сначала добавьте команду в лигу, затем включайте в сезон.')->schema([
                            Select::make('team_ids')->label('Команды сезона')->multiple()->searchable()->preload()
                                ->options(fn (?LeagueSeason $record): array => $record?->league?->teams()
                                    ->orderBy('teams.name')->pluck('teams.name', 'teams.id')->all() ?? [])
                                ->default([]),
                        ]),
                    ]),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('id')->label('ID')->sortable(),
            TextColumn::make('league.name')->label('Лига')->searchable()->sortable(),
            TextColumn::make('name')->label('Сезон')->searchable()->sortable(),
            TextColumn::make('starts_on')->label('Начало')->date('d.m.Y')->placeholder('—'),
            TextColumn::make('ends_on')->label('Окончание')->date('d.m.Y')->placeholder('—'),
            TextColumn::make('status')->label('Статус')->badge()
                ->formatStateUsing(fn (string $state): string => ['draft'=>'Черновик','active'=>'Активный','archived'=>'В архиве'][$state] ?? $state),
            TextColumn::make('teams_count')->label('Команд')->counts('teams'),
            TextColumn::make('tournaments_count')->label('Турниров')->counts('tournaments'),
        ])->defaultSort('id', 'desc')->filters([
            SelectFilter::make('status')->label('Статус')
                ->options(['draft'=>'Черновик','active'=>'Активный','archived'=>'В архиве']),
        ])->recordActions([
            EditAction::make()->label('Редактировать')->visible(fn (LeagueSeason $record): bool => self::canEdit($record)),
            Action::make('archive')->label('Архивировать')->icon('heroicon-o-archive-box')->requiresConfirmation()
                ->visible(fn (LeagueSeason $record): bool => $record->status !== 'archived' && self::canArchive())
                ->action(fn (LeagueSeason $record) => app(BackofficeSeasonEditor::class)->archive($record)),
            Action::make('delete')->label('Удалить')->color('danger')->requiresConfirmation()
                ->visible(fn (LeagueSeason $record): bool => self::canDelete($record))
                ->action(fn (LeagueSeason $record) => app(BackofficeSeasonEditor::class)->delete($record)),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSeasons::route('/'),
            'create' => CreateSeason::route('/create'),
            'edit' => EditSeason::route('/{record}/edit'),
        ];
    }
}
