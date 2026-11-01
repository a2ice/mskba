<?php

namespace App\Filament\Backoffice\Resources\Teams;

use App\Filament\Backoffice\Resources\Teams\Pages\CreateTeam;
use App\Filament\Backoffice\Resources\Teams\Pages\EditTeam;
use App\Filament\Backoffice\Resources\Teams\Pages\ListTeams;
use App\Filament\Backoffice\Resources\Teams\RelationManagers\MembersRelationManager;
use App\Filament\Backoffice\Resources\Teams\RelationManagers\LineupsRelationManager;
use App\Filament\Backoffice\Resources\Teams\RelationManagers\GamesRelationManager;
use App\Filament\Backoffice\Resources\Teams\RelationManagers\LeaguesRelationManager;
use App\Filament\Backoffice\Resources\Teams\RelationManagers\SeasonsRelationManager;
use App\Filament\Backoffice\Resources\Teams\RelationManagers\TournamentsRelationManager;
use App\Modules\Identity\Application\Services\BackofficePermissionManager;
use App\Modules\Identity\Domain\Enums\UserOperationalPermissionEnum as Permission;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Team\Domain\Enums\TeamSportTypeEnum;
use App\Modules\Team\Domain\Enums\TeamStatusEnum;
use App\Modules\Team\Domain\Models\Team;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;

final class TeamResource extends Resource
{
    protected static ?string $model = Team::class;
    protected static ?string $recordTitleAttribute = 'name';
    protected static string|\UnitEnum|null $navigationGroup = 'Контент';
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-user-group';
    protected static ?int $navigationSort = 4;

    public static function getNavigationLabel(): string { return 'Команды'; }
    public static function getModelLabel(): string { return 'команда'; }
    public static function getPluralModelLabel(): string { return 'команды'; }

    public static function authorized(): bool
    {
        $user = auth('web')->user();

        return $user !== null
            && app(BackofficePermissionManager::class)->allows($user, Permission::BACKOFFICE_ACCESS)
            && app(BackofficePermissionManager::class)->allows($user, Permission::BACKOFFICE_TEAM_MANAGE);
    }

    public static function canViewAny(): bool { return self::authorized(); }
    public static function canView(Model $record): bool { return $record instanceof Team && self::authorized(); }
    public static function canCreate(): bool { return self::authorized(); }
    public static function canEdit(Model $record): bool
    {
        return $record instanceof Team && ! $record->trashed() && self::authorized();
    }
    public static function canDelete(Model $record): bool { return self::canEdit($record); }
    public static function canRestore(Model $record): bool
    {
        return $record instanceof Team && $record->trashed() && self::authorized();
    }
    public static function canDeleteAny(): bool { return false; }
    public static function canForceDelete(Model $record): bool { return false; }
    public static function canForceDeleteAny(): bool { return false; }

    private static function options(string $enum): array
    {
        return collect($enum::cases())->mapWithKeys(
            fn ($case): array => [$case->value => $case->label()],
        )->all();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Tabs::make('Команда')->persistTabInQueryString()->tabs([
                Tab::make('Баннер')->icon('heroicon-o-photo')
                    ->visible(fn ($record): bool => $record !== null && self::canEdit($record))
                    ->schema([View::make('filament.backoffice.entity-banner')]),

                Tab::make('Контракты')->icon('heroicon-o-document-text')
                    ->visible(fn (?Team $record): bool => $record !== null
                        && \App\Filament\Backoffice\Resources\Contracts\ContractResource::canViewAny())
                    ->schema([View::make('filament.backoffice.related-contracts')
                        ->viewData(['contractScope' => 'team'])]),
                Tab::make('Основное')->icon('heroicon-o-information-circle')->schema([
                    Section::make('Информация о команде')->columns(2)->schema([
                        TextInput::make('name')->label('Название')->required()->minLength(2)->maxLength(140),
                        Select::make('status')->label('Статус')->required()
                            ->options(self::options(TeamStatusEnum::class))
                            ->default(TeamStatusEnum::ACTIVE->value),
                        Select::make('owner_user_id')->label('Владелец команды')->required()
                            ->default(fn (): ?int => auth('web')->user()?->canonical()->id)
                            ->searchable()->searchDebounce(300)
                            ->visible(fn (?Team $record): bool => $record === null)
                            ->getSearchResultsUsing(fn (string $search): array => User::query()
                                ->whereNull('canonical_user_id')->whereLike('username', '%'.$search.'%')
                                ->limit(30)->pluck('username', 'id')->all())
                            ->getOptionLabelUsing(fn ($value): ?string => User::query()->whereKey($value)->value('username'))
                            ->helperText('Владелец получит действующий контракт команды. Позднейшая смена владельца здесь не поддерживается.'),
                        CheckboxList::make('sport_types')->label('Игровые форматы')->required()->minItems(1)
                            ->options(self::options(TeamSportTypeEnum::class))
                            ->default([TeamSportTypeEnum::BASKETBALL->value])
                            ->columns(2),
                        Toggle::make('accepts_join_requests')->label('Принимать заявки в команду')
                            ->default(false),
                        Toggle::make('accepts_competition_invitations')->label('Принимать приглашения на соревнования')
                            ->default(false),
                        Textarea::make('description')->label('Описание')->rows(5)->maxLength(5000)
                            ->columnSpanFull(),
                    ]),
                ]),
                Tab::make('Логотип и цвета')->icon('heroicon-o-paint-brush')->schema([
                    Section::make('Логотип')->schema([
                        Placeholder::make('logo_preview')->label('Текущий логотип')
                            ->content(fn (?Team $record): HtmlString => new HtmlString(
                                $record?->logo ? '<img src="'.e($record->logo->publicUrl()).'" alt="Логотип команды" style="width:112px;height:112px;object-fit:contain;border-radius:12px;background:#f3f6f9;padding:8px;">'
                                    : 'Логотип пока не загружен'
                            )),
                        Placeholder::make('logo_help')->label('')
                            ->content('Добавление и удаление логотипа — через кнопки в верхней части страницы редактирования. Файлы приводятся к WebP (до 500 px).'),
                    ]),
                    Section::make('Цвета команды')->description('Текущие цвета используются публичными карточками и генерацией аватара.')
                        ->columns(2)->schema([
                            ColorPicker::make('colors.home_primary')->label('Домашняя — основной'),
                            ColorPicker::make('colors.home_secondary')->label('Домашняя — дополнительный'),
                            ColorPicker::make('colors.away_primary')->label('Гостевая — основной'),
                            ColorPicker::make('colors.away_secondary')->label('Гостевая — дополнительный'),
                        ]),
                ]),
                Tab::make('Форма')->icon('heroicon-o-swatch')->schema([
                    Section::make('Конструктор формы')
                        ->description('В разработке')
                        ->schema([
                            Placeholder::make('uniform_roadmap')->label('Предстоящая возможность')
                                ->content('Создание дизайна домашней и гостевой формы, примерка, сохранение шаблонов и заказ для команды будут доступны позже. Сейчас редактируются только цвета и логотип.'),
                        ]),
                ]),
                Tab::make('Турниры и лиги')->icon('heroicon-o-trophy')->schema([
                    Section::make('Участие в соревнованиях')->description('Текущие связи доступны во вкладках «Лиги», «Сезоны» и «Турниры» ниже.')->schema([
                        Placeholder::make('competitions_roadmap')->label('Будущие данные')
                            ->content('Состав лиги и её сезонов настраивается в административной карточке лиги. Допуски турниров проходят через заявки и приглашения.'),
                    ]),
                ]),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('id')->label('ID')->sortable(),
            // Filament ImageColumn expects a storage-relative path, not a
            // public URL. Passing /storage/... makes it encode the URL as a
            // second storage path and the image disappears.
            ImageColumn::make('logo.path')->label('Логотип')
                ->disk('public')
                ->defaultImageUrl(asset('images/team-placeholder.webp'))
                ->square()->height(42),
            TextColumn::make('name')->label('Команда')->searchable()->sortable()->weight('bold'),
            TextColumn::make('status')->label('Статус')->badge()
                ->formatStateUsing(fn ($state): string => $state instanceof TeamStatusEnum ? $state->label() : '—'),
            TextColumn::make('memberships_count')->label('Участники')->counts('memberships'),
            TextColumn::make('sport_types_label')->label('Формат')
                ->state(fn (Team $record): string => $record->sportProfiles
                    ->map(fn ($p): string => $p->sport_type->shortLabel())->implode(' · ')),
            TextColumn::make('updated_at')->label('Изменена')->dateTime('d.m.Y H:i')->sortable(),
        ])->defaultSort('id', 'desc')
            ->filters([
                SelectFilter::make('status')->label('Статус')->options(self::options(TeamStatusEnum::class)),
                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make()->label('Редактировать'),
                Action::make('archive')->label('Удалить')->color('danger')
                    ->icon('heroicon-o-trash')->requiresConfirmation()
                    ->modalDescription('Команда будет удалена мягко. Для команд с игровой историей доступен архив без удаления.')
                    ->visible(fn (Team $record): bool => self::canDelete($record))
                    ->action(fn (Team $record) => app(BackofficeTeamEditor::class)->delete($record)),
                Action::make('restore')->label('Восстановить')->icon('heroicon-o-arrow-uturn-left')
                    ->visible(fn (Team $record): bool => self::canRestore($record))
                    ->requiresConfirmation()
                    ->action(fn (Team $record) => app(BackofficeTeamEditor::class)->restore($record)),
            ]);
    }

    public static function getRelations(): array
    {
        return [MembersRelationManager::class, LineupsRelationManager::class, GamesRelationManager::class, LeaguesRelationManager::class, SeasonsRelationManager::class, TournamentsRelationManager::class];
    }

    public static function getPages(): array
    {
        return ['index' => ListTeams::route('/'),
            'create' => CreateTeam::route('/create'),
            'edit' => EditTeam::route('/{record}/edit')];
    }
}
