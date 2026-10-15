<?php

namespace App\Filament\Backoffice\Resources\SupportTickets;

use App\Filament\Backoffice\Resources\SupportTickets\Pages\ListSupportTickets;
use App\Filament\Backoffice\Resources\SupportTickets\Pages\ViewSupportTicket;
use App\Modules\Identity\Application\Services\BackofficePermissionManager;
use App\Modules\Identity\Domain\Enums\UserOperationalPermissionEnum as Permission;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Notification\Application\Services\SupportTicketAccess;
use App\Modules\Notification\Domain\Enums\SupportClosureReasonEnum;
use App\Modules\Notification\Domain\Models\SupportTicket;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final class SupportTicketResource extends Resource
{
    protected static ?string $model = SupportTicket::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-lifebuoy';

    protected static string|\UnitEnum|null $navigationGroup = 'Обращения';

    protected static ?int $navigationSort = 1;

    public static function getNavigationLabel(): string
    {
        return 'Техподдержка';
    }

    public static function getModelLabel(): string
    {
        return 'обращение';
    }

    public static function getPluralModelLabel(): string
    {
        return 'обращения техподдержки';
    }

    public static function canViewAny(): bool
    {
        $actor = auth('web')->user();

        return $actor instanceof User
            && app(BackofficePermissionManager::class)->allows($actor, Permission::BACKOFFICE_ACCESS)
            && app(SupportTicketAccess::class)->isAgent($actor);
    }

    public static function canView(Model $record): bool
    {
        $actor = auth('web')->user();

        return $record instanceof SupportTicket && $actor instanceof User && self::canViewAny()
            && app(SupportTicketAccess::class)->canRead($actor, $record, $record->thread);
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

    public static function getEloquentQuery(): Builder
    {
        if (! self::canViewAny()) {
            return parent::getEloquentQuery()->whereRaw('1 = 0');
        }

        return app(SupportTicketAccess::class)->visible(auth('web')->user())
            ->with(['user', 'closer', 'thread.lead']);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Обращение')->columns(2)->schema([
                TextEntry::make('id')->label('Номер'),
                TextEntry::make('status')->label('Статус')
                    ->formatStateUsing(fn (string $state): string => $state === 'open' ? 'Открыто' : 'Закрыто'),
                TextEntry::make('subject')->label('Тема')->columnSpanFull(),
                TextEntry::make('user.username')->label('Заявитель'),
                TextEntry::make('thread.lead.username')->label('Главный ответственный')->placeholder('Не назначен'),
                TextEntry::make('close_reason')->label('Причина закрытия')
                    ->formatStateUsing(fn ($state): string => SupportClosureReasonEnum::tryFrom((string) $state)?->label() ?? 'Не указана')
                    ->visible(fn (SupportTicket $record): bool => $record->status === 'closed'),
                TextEntry::make('closer.username')->label('Закрыл')->placeholder('Не указано')
                    ->visible(fn (SupportTicket $record): bool => $record->status === 'closed'),
                TextEntry::make('close_description')->label('Описание причины')->placeholder('Не указано')
                    ->columnSpanFull()->visible(fn (SupportTicket $record): bool => filled($record->close_description)),
                TextEntry::make('created_at')->label('Создано')->dateTime('d.m.Y H:i'),
            ]),
            Section::make('История сообщений и действий — только просмотр')
                ->schema([
                    ViewEntry::make('history')
                        ->view('filament.backoffice.support-ticket-history')->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('id')->label('№')->sortable(),
            TextColumn::make('subject')->label('Тема')->searchable()->limit(60),
            TextColumn::make('user.username')->label('Пользователь')->searchable(),
            TextColumn::make('status')->label('Статус')->badge()
                ->formatStateUsing(fn (string $state): string => $state === 'open' ? 'Открыто' : 'Закрыто'),
            TextColumn::make('thread.lead.username')->label('Главный')->placeholder('Не назначен'),
            TextColumn::make('created_at')->label('Дата')->dateTime('d.m.Y H:i')->sortable(),
        ])->filters([
            SelectFilter::make('status')->label('Статус')
                ->options(['open' => 'Открыто', 'closed' => 'Закрыто']),
        ])->defaultSort('id', 'desc')->recordActions([ViewAction::make()->label('Просмотр')]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSupportTickets::route('/'),
            'view' => ViewSupportTicket::route('/{record}'),
        ];
    }
}
