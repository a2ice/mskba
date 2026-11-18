<?php

namespace App\Filament\Backoffice\Resources\PlayOpportunities;

use App\Filament\Backoffice\Resources\PlayOpportunities\Pages\EditPlayOpportunity;
use App\Filament\Backoffice\Resources\PlayOpportunities\Pages\ListPlayOpportunities;
use App\Modules\Identity\Application\Services\BackofficePermissionManager;
use App\Modules\Identity\Domain\Enums\UserOperationalPermissionEnum as Permission;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\PlayInterest\Domain\Models\PlayOpportunity;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

final class PlayOpportunityResource extends Resource
{
    protected static ?string $model = PlayOpportunity::class;
    protected static string|\UnitEnum|null $navigationGroup = 'Сообщества';
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-user-group';
    protected static ?int $navigationSort = 1;
    protected static ?string $recordTitleAttribute = 'generated_title';

    public static function getNavigationLabel(): string { return 'Группы интересов'; }
    public static function getModelLabel(): string { return 'группа интересов'; }
    public static function getPluralModelLabel(): string { return 'Группы интересов'; }

    public static function authorized(): bool
    {
        $user = auth('web')->user();
        return $user instanceof User
            && app(BackofficePermissionManager::class)->allows($user, Permission::BACKOFFICE_ACCESS)
            && app(BackofficePermissionManager::class)->allows($user, Permission::BACKOFFICE_EVENT_MANAGE);
    }

    public static function canViewAny(): bool { return self::authorized(); }
    public static function canView(Model $record): bool
    {
        return $record instanceof PlayOpportunity && self::authorized();
    }
    public static function canEdit(Model $record): bool { return self::canView($record); }
    public static function canCreate(): bool { return false; }
    public static function canDelete(Model $record): bool { return false; }
    public static function canDeleteAny(): bool { return false; }
    public static function canForceDelete(Model $record): bool { return false; }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Название и администратор')
                ->description('Действия из Filament попадают в историю группы и закрывают открытые выборы при служебной смене.')
                ->schema([
                    TextInput::make('generated_title')->label('Название от системы')
                        ->disabled()->dehydrated(false),
                    TextInput::make('custom_title')->label('Своё название')
                        ->maxLength(80)->placeholder('Пустое поле — автоматическое название'),
                    Select::make('admin_user_id')->label('Администратор группы')
                        ->placeholder('Не назначен')->nullable()->searchable()
                        ->options(function (?PlayOpportunity $record): array {
                            if ($record === null) return [];
                            return $record->members()->where('status', 'active')
                                ->with('user')->get()->filter(fn($m)=>$m->user !== null
                                    && $m->user->isConfirmed() && ! $m->user->isBlocked())
                                ->mapWithKeys(fn($m)=>[$m->user_id=>$m->user->username ?: 'Игрок #'.$m->user_id])
                                ->all();
                        }),
                    Textarea::make('override_reason')->label('Причина изменения')
                        ->helperText('Если меняете название или назначение администратора, укажите служебную причину не короче 20 символов.')
                        ->rows(3)->maxLength(1000)->dehydrated(),
                ]),
            Section::make('Информация о совпадении')
                ->schema([
                    TextInput::make('public_id')->label('Постоянный идентификатор')->disabled()->dehydrated(false),
                    TextInput::make('status')->label('Состояние')->disabled()->dehydrated(false),
                    TextInput::make('format')->label('Общий формат')->disabled()->dehydrated(false),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('id')->sortable(),
            TextColumn::make('generated_title')->label('Название группы')
                ->formatStateUsing(fn($state, PlayOpportunity $record): string=>$record->displayTitle())
                ->searchable()->sortable()->weight('bold'),
            TextColumn::make('status')->label('Статус')->badge(),
            TextColumn::make('format')->label('Формат')->placeholder('—'),
            TextColumn::make('members_count')->label('Участников')->counts('members'),
            TextColumn::make('administrator.username')->label('Администратор')->placeholder('Не выбран'),
            TextColumn::make('updated_at')->label('Обновлена')->dateTime('d.m.Y H:i')->sortable(),
        ])->defaultSort('id','desc')
            ->recordActions([EditAction::make()->label('Открыть')]);
    }

    public static function getPages(): array
    {
        return [
            'index'=>ListPlayOpportunities::route('/'),
            'edit'=>EditPlayOpportunity::route('/{record}/edit'),
        ];
    }
}
