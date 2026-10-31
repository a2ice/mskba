<?php

namespace App\Filament\Backoffice\Resources\Tournaments;

use App\Filament\Backoffice\Resources\Competitions\CompetitionCatalogEditor;
use App\Filament\Backoffice\Resources\Tournaments\Pages\CreateTournament;
use App\Filament\Backoffice\Resources\Tournaments\Pages\EditTournament;
use App\Filament\Backoffice\Resources\Tournaments\Pages\ListTournaments;
use App\Filament\Backoffice\Resources\Tournaments\RelationManagers\AdmissionsRelationManager;
use App\Filament\Backoffice\Resources\Tournaments\RelationManagers\EntriesRelationManager;
use App\Filament\Backoffice\Resources\Tournaments\RelationManagers\MatchesRelationManager;
use App\Modules\Event\Domain\Enums\GameFormatEnum;
use App\Modules\Identity\Application\Services\BackofficePermissionManager;
use App\Modules\Identity\Domain\Enums\UserOperationalPermissionEnum as Permission;
use App\Modules\Tournament\Domain\Enums\TournamentEnrollmentPolicyEnum;
use App\Modules\Tournament\Domain\Enums\TournamentRecruitmentModeEnum;
use App\Modules\Tournament\Domain\Enums\TournamentStatusEnum;
use App\Modules\League\Domain\Models\League;
use App\Modules\League\Domain\Models\LeagueSeason;
use App\Modules\Tournament\Domain\Models\Tournament;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

final class TournamentResource extends Resource
{
    protected static ?string $model = Tournament::class;
    protected static ?string $recordTitleAttribute = 'title';
    protected static string|\UnitEnum|null $navigationGroup = 'Контент';
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-flag';
    protected static ?int $navigationSort = 7;

    public static function getNavigationLabel(): string { return 'Турниры'; }
    public static function getModelLabel(): string { return 'турнир'; }
    public static function getPluralModelLabel(): string { return 'турниры'; }

    public static function allows(Permission $permission): bool
    {
        $user = auth('web')->user();
        return $user !== null
            && app(BackofficePermissionManager::class)->allows($user, Permission::BACKOFFICE_ACCESS)
            && app(BackofficePermissionManager::class)->allows($user, $permission);
    }

    public static function canViewAny(): bool { return self::allows(Permission::BACKOFFICE_TOURNAMENT_VIEW); }
    public static function canView(Model $record): bool { return $record instanceof Tournament && self::canViewAny(); }
    public static function canCreate(): bool { return self::allows(Permission::BACKOFFICE_TOURNAMENT_CREATE); }
    public static function canEdit(Model $record): bool { return $record instanceof Tournament && ! $record->trashed() && self::allows(Permission::BACKOFFICE_TOURNAMENT_EDIT); }
    public static function canDelete(Model $record): bool { return $record instanceof Tournament && ! $record->trashed() && self::allows(Permission::BACKOFFICE_TOURNAMENT_DELETE); }
    public static function canRestore(Model $record): bool { return $record instanceof Tournament && $record->trashed() && self::allows(Permission::BACKOFFICE_TOURNAMENT_DELETE); }
    public static function canManageParticipants(): bool { return self::canViewAny() && self::allows(Permission::BACKOFFICE_TOURNAMENT_PARTICIPANTS_MANAGE); }
    public static function canDeleteAny(): bool { return false; }
    public static function canForceDelete(Model $record): bool { return false; }
    public static function canForceDeleteAny(): bool { return false; }

    private static function options(string $enum): array
    {
        return collect($enum::cases())->mapWithKeys(fn ($item): array => [$item->value => $item->label()])->all();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Tabs::make('Турнир')->persistTabInQueryString()->tabs([
                Tab::make('Баннер')->icon('heroicon-o-photo')
                    ->visible(fn ($record): bool => $record !== null && self::canEdit($record))
                    ->schema([View::make('filament.backoffice.entity-banner')]),

                Tab::make('Основное')->schema([
                    Section::make('Параметры турнира')->columns(2)->schema([
                        TextInput::make('title')->label('Название')->required()->minLength(2)->maxLength(150)->columnSpanFull(),
                        Select::make('status')->label('Статус')->required()
                            ->options(self::options(TournamentStatusEnum::class))
                            ->default(TournamentStatusEnum::UNCONFIRMED->value),
                        Select::make('format')->label('Формат')->native(false)
                            ->options([
                                GameFormatEnum::BASKETBALL_5X5->value => GameFormatEnum::BASKETBALL_5X5->label(),
                                GameFormatEnum::STREETBALL_3X3->value => GameFormatEnum::STREETBALL_3X3->label(),
                                GameFormatEnum::STREETBALL_1X1->value => GameFormatEnum::STREETBALL_1X1->label(),
                            ]),
                        DatePicker::make('starts_on')->label('Начало')->required()->native(false),
                        DatePicker::make('ends_on')->label('Окончание')->native(false),
                        Textarea::make('short_description')->label('Краткое описание')->rows(3)->columnSpanFull(),
                        Textarea::make('full_description')->label('Полное описание')->rows(6)->columnSpanFull(),
                        Textarea::make('status_comment')->label('Комментарий к статусу')->rows(2)->columnSpanFull(),
                    ]),
                ]),
                Tab::make('Лига и сезон')->schema([
                    Section::make('Принадлежность')->columns(2)
                        ->description('Оба поля необязательны. Турнир может проводиться вне лиги либо относиться к лиге без сезона.')
                        ->schema([
                            Select::make('league_id')->label('Лига')->searchable()->preload()->live()
                                ->options(fn (?Tournament $record): array => League::query()
                                    ->where(function ($q) use ($record) {
                                        $q->where('status', 'active');
                                        if ($record?->league_id) $q->orWhereKey($record->league_id);
                                    })
                                    ->orderBy('name')->pluck('name', 'id')->all())
                                ->afterStateUpdated(fn (Set $set) => $set('league_season_id', null)),
                            Select::make('league_season_id')->label('Сезон')->searchable()->preload()
                                ->options(fn (Get $get): array => LeagueSeason::query()
                                    ->where('league_id', (int) ($get('league_id') ?? 0))
                                    ->orderBy('name')->pluck('name', 'id')->all())
                                ->disabled(fn (Get $get): bool => ! (bool) $get('league_id'))
                                ->dehydrated(),
                        ]),
                ]),
                Tab::make('Регламент')->schema([
                    Section::make('Регламент и набор участников')->columns(2)->schema([
                        Select::make('recruitment_mode')->label('Набор участников')->required()
                            ->options(self::options(TournamentRecruitmentModeEnum::class))
                            ->default(TournamentRecruitmentModeEnum::PREFORMED_TEAMS->value),
                        Select::make('enrollment_policy')->label('Режим проведения')->required()
                            ->options(self::options(TournamentEnrollmentPolicyEnum::class))
                            ->default(TournamentEnrollmentPolicyEnum::FIXED_POOL->value),
                        TextInput::make('round_robin_legs')->label('Количество кругов')->integer()
                            ->minValue(1)->maxValue(2)->default(1)->required(),
                    ]),
                ]),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('id')->label('ID')->sortable(),
            TextColumn::make('title')->label('Турнир')->searchable()->sortable()->weight('bold'),
            TextColumn::make('league.name')->label('Лига')->placeholder('Независимый'),
            TextColumn::make('leagueSeason.name')->label('Сезон')->placeholder('Без сезона'),
            TextColumn::make('starts_on')->label('Начало')->date('d.m.Y')->sortable(),
            TextColumn::make('ends_on')->label('Окончание')->date('d.m.Y')->placeholder('Не указано'),
            TextColumn::make('status')->label('Статус')->badge()
                ->formatStateUsing(fn ($state): string => $state instanceof TournamentStatusEnum ? $state->label() : '—'),
            TextColumn::make('entries_count')->label('Участники')->counts('entries'),
        ])->defaultSort('id', 'desc')
        ->filters([
            SelectFilter::make('league_id')->label('Лига')
                ->options(fn (): array => League::query()->orderBy('name')->pluck('name', 'id')->all()),
            SelectFilter::make('status')->label('Статус')->options(self::options(TournamentStatusEnum::class)),
            TrashedFilter::make(),
        ])
        ->recordActions([
            EditAction::make()->label('Редактировать'),
            Action::make('deleteTournament')->label('Удалить')->icon('heroicon-o-trash')->color('danger')
                ->requiresConfirmation()->visible(fn (Tournament $record): bool => self::canDelete($record))
                ->action(function (Tournament $record): void {
                    app(CompetitionCatalogEditor::class)->deleteTournament($record);
                    Notification::make()->success()->title('Турнир удалён')->send();
                }),
            Action::make('restoreTournament')->label('Восстановить')->icon('heroicon-o-arrow-uturn-left')
                ->requiresConfirmation()->visible(fn (Tournament $record): bool => self::canRestore($record))
                ->action(function (Tournament $record): void {
                    app(CompetitionCatalogEditor::class)->restoreTournament($record);
                    Notification::make()->success()->title('Турнир восстановлен')->send();
                }),
        ]);
    }

    public static function getRelations(): array
    {
        return [AdmissionsRelationManager::class, EntriesRelationManager::class, MatchesRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTournaments::route('/'),
            'create' => CreateTournament::route('/create'),
            'edit' => EditTournament::route('/{record}/edit'),
        ];
    }
}
