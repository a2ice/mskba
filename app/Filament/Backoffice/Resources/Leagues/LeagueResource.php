<?php

namespace App\Filament\Backoffice\Resources\Leagues;

use App\Filament\Backoffice\Resources\Competitions\CompetitionCatalogEditor;
use App\Filament\Backoffice\Resources\Leagues\Pages\CreateLeague;
use App\Filament\Backoffice\Resources\Leagues\Pages\EditLeague;
use App\Filament\Backoffice\Resources\Leagues\Pages\ListLeagues;
use App\Modules\Identity\Application\Services\BackofficePermissionManager;
use App\Modules\Identity\Domain\Enums\UserOperationalPermissionEnum as Permission;
use App\Modules\Tournament\Domain\Models\League;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
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

    private static function authorized(): bool
    {
        $user = auth('web')->user();
        return $user !== null
            && app(BackofficePermissionManager::class)->allows($user, Permission::BACKOFFICE_ACCESS)
            && app(BackofficePermissionManager::class)->allows($user, Permission::BACKOFFICE_LEAGUE_MANAGE);
    }

    public static function canViewAny(): bool { return self::authorized(); }
    public static function canView(Model $record): bool { return $record instanceof League && self::authorized(); }
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
            Section::make('Лига')->description('Лига может существовать самостоятельно, без сезонов и турниров.')
                ->columns(2)->schema([
                    TextInput::make('name')->label('Название')->required()->minLength(2)->maxLength(160),
                    TextInput::make('slug')->label('Адресный идентификатор')->maxLength(180)
                        ->helperText('Если оставить пустым, сформируется из названия.'),
                    Select::make('status')->label('Статус')->required()
                        ->options(['active' => 'Активна', 'archived' => 'В архиве'])
                        ->default('active'),
                    Textarea::make('description')->label('Описание')->rows(5)->maxLength(10000)->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('id')->label('ID')->sortable(),
            TextColumn::make('name')->label('Лига')->searchable()->sortable()->weight('bold'),
            TextColumn::make('status')->label('Статус')->badge()
                ->formatStateUsing(fn (string $state): string => $state === 'active' ? 'Активна' : 'В архиве'),
            TextColumn::make('seasons_count')->label('Сезоны')->counts('seasons'),
            TextColumn::make('tournaments_count')->label('Турниры')->counts('tournaments'),
            TextColumn::make('updated_at')->label('Изменена')->dateTime('d.m.Y H:i')->sortable(),
        ])->defaultSort('id', 'desc')
        ->filters([
            SelectFilter::make('status')->label('Статус')->options(['active' => 'Активна', 'archived' => 'В архиве']),
            TrashedFilter::make(),
        ])
        ->recordActions([
            EditAction::make()->label('Редактировать'),
            Action::make('deleteLeague')->label('Удалить')->color('danger')->icon('heroicon-o-trash')
                ->requiresConfirmation()->visible(fn (League $record): bool => self::canDelete($record))
                ->action(function (League $record): void {
                    app(CompetitionCatalogEditor::class)->deleteLeague($record);
                    Notification::make()->success()->title('Лига удалена')->send();
                }),
            Action::make('restoreLeague')->label('Восстановить')->icon('heroicon-o-arrow-uturn-left')
                ->requiresConfirmation()->visible(fn (League $record): bool => self::canRestore($record))
                ->action(function (League $record): void {
                    app(CompetitionCatalogEditor::class)->restoreLeague($record);
                    Notification::make()->success()->title('Лига восстановлена')->send();
                }),
        ]);
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
