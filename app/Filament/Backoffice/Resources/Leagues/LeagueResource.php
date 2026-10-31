<?php

namespace App\Filament\Backoffice\Resources\Leagues;

use App\Filament\Backoffice\Resources\Leagues\Pages\CreateLeague;
use App\Filament\Backoffice\Resources\Leagues\Pages\EditLeague;
use App\Filament\Backoffice\Resources\Leagues\Pages\ListLeagues;
use App\Filament\Backoffice\Resources\Leagues\RelationManagers\SeasonsRelationManager;
use App\Modules\Identity\Application\Services\BackofficePermissionManager;
use App\Modules\Identity\Domain\Enums\UserOperationalPermissionEnum as Permission;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\League\Domain\Models\League;
use App\Modules\Team\Domain\Models\Team;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

final class LeagueResource extends Resource
{
    protected static ?string $model = League::class;
    protected static ?string $recordTitleAttribute = 'name';
    protected static string|\UnitEnum|null $navigationGroup = 'Контент';
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-trophy';
    protected static ?int $navigationSort = 5;

    public static function getNavigationLabel(): string { return 'Лиги'; }
    public static function getModelLabel(): string { return 'лига'; }
    public static function getPluralModelLabel(): string { return 'лиги'; }

    public static function allows(Permission $permission): bool
    {
        $user = auth('web')->user();
        return $user !== null
            && app(BackofficePermissionManager::class)->allows($user, Permission::BACKOFFICE_ACCESS)
            && app(BackofficePermissionManager::class)->allows($user, $permission);
    }

    public static function canViewAny(): bool { return self::allows(Permission::BACKOFFICE_LEAGUE_VIEW); }
    public static function canView(Model $record): bool { return $record instanceof League && self::canViewAny(); }
    public static function canCreate(): bool { return self::allows(Permission::BACKOFFICE_LEAGUE_CREATE); }
    public static function canEdit(Model $record): bool { return $record instanceof League && self::allows(Permission::BACKOFFICE_LEAGUE_EDIT); }
    public static function canDelete(Model $record): bool { return $record instanceof League && self::canArchive(); }
    public static function canArchive(): bool { return self::allows(Permission::BACKOFFICE_LEAGUE_DELETE); }
    public static function canManageTeams(): bool { return self::allows(Permission::BACKOFFICE_LEAGUE_TEAMS_MANAGE); }
    public static function canDeleteAny(): bool { return false; }
    public static function canForceDelete(Model $record): bool { return false; }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Tabs::make('Лига')->persistTabInQueryString()->tabs([
                Tab::make('Основное')->icon('heroicon-o-information-circle')->schema([
                    Section::make('Информация о лиге')->columns(2)->schema([
                        TextInput::make('name')->label('Название')->required()->maxLength(150)->minLength(2),
                        Select::make('status')->label('Статус')->required()
                            ->options(['draft' => 'Черновик', 'active' => 'Активная', 'archived' => 'В архиве'])->default('draft'),
                        Select::make('owner_user_id')->label('Владелец')->required()
                            ->default(fn (): ?int => auth('web')->user()?->canonical()->id)
                            ->visible(fn (?League $record): bool => $record === null)
                            ->searchable()->getSearchResultsUsing(fn (string $search): array => User::query()
                                ->whereNull('canonical_user_id')->whereLike('username', '%'.$search.'%')
                                ->limit(30)->pluck('username', 'id')->all())
                            ->getOptionLabelUsing(fn ($value): ?string => User::query()->whereKey($value)->value('username'))
                            ->helperText('По умолчанию владелец — создатель. После создания владелец здесь не меняется.'),
                        Textarea::make('description')->label('Описание')->rows(5)->columnSpanFull(),
                        FileUpload::make('logo_path')->label('Логотип лиги')
                            ->image()->disk('public')->directory('leagues/logos')->maxSize(5120)->columnSpanFull(),
                    ]),
                ]),
                Tab::make('Команды')->icon('heroicon-o-user-group')
                    ->visible(fn (): bool => self::canManageTeams())->schema([
                        Section::make('Команды лиги')->description('Команда может состоять в нескольких лигах. Состав сезона настраивается отдельно.')->schema([
                            Select::make('team_ids')->label('Команды')->multiple()->searchable()->preload()
                                ->options(fn (): array => Team::query()->competitionEligible()->orderBy('name')->pluck('name', 'id')->all())
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
            ImageColumn::make('logo_path')->label('Логотип')->disk('public')->square()->height(36),
            TextColumn::make('name')->label('Лига')->searchable()->sortable(),
            TextColumn::make('owner.username')->label('Владелец')->searchable(),
            TextColumn::make('status')->label('Статус')->badge()
                ->formatStateUsing(fn (string $state): string => ['draft'=>'Черновик','active'=>'Активная','archived'=>'В архиве'][$state] ?? $state),
            TextColumn::make('seasons_count')->label('Сезонов')->counts('seasons'),
            TextColumn::make('teams_count')->label('Команд')->counts('teams'),
            TextColumn::make('updated_at')->label('Изменена')->dateTime('d.m.Y H:i')->sortable(),
        ])->defaultSort('id', 'desc')->filters([
            SelectFilter::make('status')->label('Статус')
                ->options(['draft'=>'Черновик','active'=>'Активная','archived'=>'В архиве']),
        ])->recordActions([
            EditAction::make()->label('Редактировать')->visible(fn (League $record): bool => self::canEdit($record)),
            Action::make('archive')->label('Архивировать')->icon('heroicon-o-archive-box')->requiresConfirmation()
                ->visible(fn (League $record): bool => $record->status !== 'archived' && self::canArchive())
                ->action(fn (League $record) => app(BackofficeLeagueEditor::class)->archive($record)),
            Action::make('delete')->label('Удалить')->color('danger')->icon('heroicon-o-trash')->requiresConfirmation()
                ->visible(fn (League $record): bool => self::canDelete($record))
                ->action(fn (League $record) => app(BackofficeLeagueEditor::class)->delete($record)),
        ]);
    }

    public static function getRelations(): array
    {
        return [SeasonsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLeagues::route('/'),
            'create' => CreateLeague::route('/create'),
            'edit' => EditLeague::route('/{record}/edit'),
        ];
    }
}
