<?php

namespace App\Filament\Backoffice\Resources\OperatorConversations;

use App\Filament\Backoffice\Resources\OperatorConversations\Pages\ListOperatorConversations;
use App\Filament\Backoffice\Resources\OperatorConversations\Pages\ViewOperatorConversation;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Notification\Application\Services\OperatorConversationAccess;
use App\Modules\Notification\Domain\Models\OperatorConversation;
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

final class OperatorConversationResource extends Resource
{
    protected static ?string $model = OperatorConversation::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-chat-bubble-left-right';

    protected static string|\UnitEnum|null $navigationGroup = 'Обращения';

    protected static ?int $navigationSort = 2;

    public static function getNavigationLabel(): string
    {
        return 'Операторские диалоги';
    }

    public static function getModelLabel(): string
    {
        return 'операторский диалог';
    }

    public static function getPluralModelLabel(): string
    {
        return 'операторские диалоги';
    }

    public static function canViewAny(): bool
    {
        $user = auth('web')->user();

        return $user instanceof User && app(OperatorConversationAccess::class)->isOperator($user);
    }

    public static function canView(Model $record): bool
    {
        $user = auth('web')->user();

        return $user instanceof User && $record instanceof OperatorConversation
            && self::canViewAny()
            && app(OperatorConversationAccess::class)->canRead($user, $record);
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
        $user = auth('web')->user();

        return parent::getEloquentQuery()
            ->where('operator_user_id', $user?->canonical()->id ?? 0)
            ->with(['recipient', 'latestMessage']);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('id')->label('№')->sortable(),
            TextColumn::make('recipient.username')->label('Пользователь')->searchable(),
            TextColumn::make('purpose')->label('Назначение')
                ->formatStateUsing(static fn (): string => 'Сервисный'),
            TextColumn::make('latestMessage.body')->label('Последнее сообщение')->limit(60),
            TextColumn::make('updated_at')->label('Обновлён')->dateTime('d.m.Y H:i')->sortable(),
        ])->defaultSort('updated_at', 'desc')
            ->recordActions([ViewAction::make()->label('Открыть')]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Операторский диалог')->schema([
                TextEntry::make('recipient.username')->label('Пользователь'),
                ViewEntry::make('conversation')->label('Переписка')
                    ->view('filament.backoffice.operator-conversation-history'),
            ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOperatorConversations::route('/'),
            'view' => ViewOperatorConversation::route('/{record}'),
        ];
    }
}
