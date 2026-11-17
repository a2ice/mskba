<?php

namespace App\Filament\Backoffice\Resources\OperatorUsers;

use App\Filament\Backoffice\Resources\OperatorUsers\Pages\ListOperatorUsers;
use App\Filament\Backoffice\Resources\OperatorConversations\OperatorConversationResource;
use App\Modules\Notification\Application\Services\OperatorConversationAccess;
use App\Modules\Notification\Application\Services\OperatorConversationMessaging;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use App\Filament\Backoffice\Resources\OperatorUsers\Pages\ViewOperatorUser;
use App\Modules\Identity\Application\Services\UserOperationalPermissionChecker;
use App\Modules\Identity\Domain\Enums\UserOperationalPermissionEnum as Permission;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Portal\Application\Services\OperatorActivityService;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final class OperatorUserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-signal';

    protected static string|\UnitEnum|null $navigationGroup = 'Операторский центр';

    protected static ?int $navigationSort = 1;

    public static function getNavigationLabel(): string
    {
        return 'Пользователи';
    }

    public static function getModelLabel(): string
    {
        return 'пользователь';
    }

    public static function getPluralModelLabel(): string
    {
        return 'пользователи';
    }

    public static function canViewAny(): bool
    {
        $actor = auth('web')->user();

        return $actor !== null && app(UserOperationalPermissionChecker::class)
            ->allows($actor, Permission::OPERATOR_PRESENCE_VIEW)
            && app(UserOperationalPermissionChecker::class)
                ->allows($actor, Permission::BACKOFFICE_ACCESS);
    }

    public static function canView(Model $record): bool
    {
        return self::canViewAny() && $record instanceof User
            && $record->canonical_user_id === null
            && $record->system_role !== UserSystemRoleEnum::SYSTEM;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereNull('canonical_user_id')
            ->where('system_role', '!=', UserSystemRoleEnum::SYSTEM->value);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('username')->label('Пользователь')->searchable()->sortable()->weight('bold'),
            TextColumn::make('current_presence')
                ->label('Присутствие')->badge()
                ->state(static fn (User $record): string =>
                    app(OperatorActivityService::class)->state($record)['online'] ?? false ? 'Онлайн' : 'Офлайн')
                ->color(static fn (string $state): string => $state === 'Онлайн' ? 'success' : 'gray'),
            TextColumn::make('current_path')->label('Страница')
                ->state(static function (User $record): string {
                    $state = app(OperatorActivityService::class)->state($record);

                    return ($state['online'] ?? false) ? $state['path'] : '—';
                }),
            TextColumn::make('last_seen')->label('Последняя активность')
                ->state(static function (User $record): string {
                    $timestamp = app(OperatorActivityService::class)->state($record)['at'] ?? null;

                    return $timestamp ? date('d.m.Y H:i:s', $timestamp) : '—';
                }),
        ])
            ->defaultSort('id', 'desc')
            ->recordActions([
                ViewAction::make()->label('История сессии'),
                Action::make('write')
                    ->label('Написать')
                    ->icon('heroicon-o-chat-bubble-left-right')
                    ->visible(fn (User $record): bool => auth('web')->user() instanceof User
                        && app(OperatorConversationAccess::class)->mayContact(auth('web')->user(), $record))
                    ->modalHeading(fn (User $record): string => 'Написать @'.$record->username)
                    ->schema([
                        Textarea::make('body')
                            ->label('Сервисное сообщение')
                            ->required()->maxLength(2000)->rows(4),
                    ])
                    ->modalSubmitActionLabel('Отправить')
                    ->action(function (User $record, array $data): \Illuminate\Http\RedirectResponse {
                        $actor = auth('web')->user()->canonical();
                        $conversation = app(OperatorConversationMessaging::class)
                            ->start($actor, $record, $data['body']);
                        return redirect()->to(OperatorConversationResource::getUrl('view', [
                            'record' => $conversation,
                        ]));
                    }),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Присутствие на сайте')->schema([
                TextEntry::make('username')->label('Пользователь'),
                ViewEntry::make('operator_activity')
                    ->label('История переходов')
                    ->view('filament.backoffice.operator-user-activity'),
            ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOperatorUsers::route('/'),
            'view' => ViewOperatorUser::route('/{record}'),
        ];
    }
}
