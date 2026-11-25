<?php

namespace App\Filament\Backoffice\Resources\AccountInvitations;

use App\Filament\Backoffice\Resources\AccountInvitations\Pages\ListAccountInvitations;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Invitation\Application\Services\AccountInvitationManager;
use App\Modules\Invitation\Domain\Models\AccountInvitation;
use App\Modules\Venue\Domain\Models\Venue;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Throwable;

final class AccountInvitationResource extends Resource
{
    protected static ?string $model = AccountInvitation::class;

    protected static string|\UnitEnum|null $navigationGroup = 'Пользователи и доступ';
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-envelope-open';
    protected static ?int $navigationSort = 2;

    public static function getNavigationLabel(): string { return 'Приглашения'; }
    public static function getModelLabel(): string { return 'приглашение'; }
    public static function getPluralModelLabel(): string { return 'приглашения'; }

    public static function canViewAny(): bool
    {
        $user = auth('web')->user()?->canonical();
        return $user !== null && $user->isConfirmed()
            && $user->system_role === UserSystemRoleEnum::SUPERADMIN;
    }

    public static function canView(Model $record): bool
    {
        return $record instanceof AccountInvitation && self::canViewAny();
    }

    public static function canCreate(): bool { return false; }
    public static function canEdit(Model $record): bool { return false; }
    public static function canDelete(Model $record): bool { return false; }
    public static function canDeleteAny(): bool { return false; }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->when(! self::canViewAny(), fn (Builder $query) => $query->whereRaw('1 = 0'))
            ->with(['issuer', 'acceptedUser'])
            ->withExists(['events as has_restoration' => fn (Builder $query) => $query->where('event', 'restored')]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordAction('details')
            ->columns([
                TextColumn::make('id')->label('№')->sortable(),
                TextColumn::make('title')->label('Название')->searchable()->wrap(),
                TextColumn::make('contact_value')->label('Контакт')->searchable(),
                TextColumn::make('scope_id')->label('Объект')
                    ->formatStateUsing(fn ($state, AccountInvitation $record): string => $record->targetName().' (#'.$state.')'),
                TextColumn::make('role')->label('Цель')
                    ->formatStateUsing(fn ($state): string => match ($state) {
                        'owner' => 'Владелец', 'manager' => 'Менеджер',
                        'staff' => 'Сотрудник', 'booking_operator' => 'Оператор бронирований',
                        default => $state,
                    }),
                TextColumn::make('invitation_status')->label('Приглашение')->badge()
                    ->state(fn (AccountInvitation $record): string => $record->invitationStatusLabel()),
                TextColumn::make('system_status')->label('Системный статус')->badge()
                    ->state(fn (AccountInvitation $record): string => $record->systemStatusLabel()),
                TextColumn::make('status')->label('Процедура')->badge()
                    ->formatStateUsing(fn ($state, AccountInvitation $record): string => $record->statusLabel()),
                TextColumn::make('progress')->label('Этап')
                    ->formatStateUsing(fn ($state, AccountInvitation $record): string => $record->progressLabel()),
                TextColumn::make('issuer.username')->label('Отправитель')->placeholder('—'),
                TextColumn::make('sent_at')->label('Отправлено')->dateTime('d.m.Y H:i')->sortable()->placeholder('—'),
                TextColumn::make('viewed_at')->label('Открыто')->tooltip('Техническое открытие ссылки, в том числе почтовым сканером')->dateTime('d.m.Y H:i')->sortable()->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('contact_verified_at')->label('Принято / контакт подтверждён')->dateTime('d.m.Y H:i')->sortable()->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('views_count')->label('Открытия')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('expires_at')->label('Ссылка до')->dateTime('d.m.Y H:i')->sortable(),
                TextColumn::make('acceptedUser.username')->label('Аккаунт')->placeholder('Не создан'),
            ])
            ->filters([
                SelectFilter::make('status')->label('Статус')
                    ->options(['pending' => 'В процессе', 'completed' => 'Завершено', 'revoked' => 'Отозвано']),
                SelectFilter::make('role')->label('Цель')
                    ->options(['owner' => 'Владелец', 'staff' => 'Сотрудник',
                        'manager' => 'Менеджер', 'booking_operator' => 'Оператор бронирований']),
            ])
            ->headerActions([
                Action::make('issue')->label('Создать аккаунт и пригласить пользователя')
                    ->icon('heroicon-o-user-plus')->modalWidth('lg')
                    ->schema([
                        Select::make('scope_type')->label('Контекст')->required()->default('venue')
                            ->options(['venue' => 'Площадка']),
                        Select::make('scope_id')->label('Площадка')->required()->searchable()
                            ->getSearchResultsUsing(fn (string $search): array =>
                                Venue::query()->whereNull('deleted_at')
                                    ->where('name', 'like', '%'.$search.'%')
                                    ->limit(30)->pluck('name', 'id')->all())
                            ->getOptionLabelUsing(fn ($value): ?string => Venue::query()->find($value)?->name),
                        Select::make('role')->label('Цель приглашения')->required()->default('owner')
                            ->options([
                                'owner' => 'Регистрация владельца площадки',
                                'staff' => 'Регистрация сотрудника площадки',
                                'manager' => 'Регистрация менеджера площадки',
                                'booking_operator' => 'Регистрация оператора бронирований',
                            ]),
                        TextInput::make('email')->label('Email получателя')->email()->required()->maxLength(255),
                        TextInput::make('title')->label('Название приглашения')->maxLength(160)
                            ->helperText('Необязательно: по умолчанию будет сформировано из роли и названия площадки.'),
                        Textarea::make('description')->label('Описание для получателя')
                            ->maxLength(2000)->rows(3),
                        Select::make('days')->label('Срок действия ссылки')->required()->default(7)
                            ->options([1 => '1 день', 3 => '3 дня', 7 => '7 дней', 14 => '14 дней', 30 => '30 дней']),
                    ])
                    ->action(function (array $data): void {
                        abort_unless(self::canViewAny(), 403);
                        try {
                            [$invitation, $url] = app(AccountInvitationManager::class)->create(
                                auth('web')->user(), (string) $data['email'], (string) $data['scope_type'],
                                (int) $data['scope_id'], (string) $data['role'],
                                (string) ($data['title'] ?? ''), $data['description'] ?? null, (int) $data['days']
                            );
                            Notification::make()->success()
                                ->title('Приглашение #'.$invitation->id.' отправлено')
                                ->body('Уникальная ссылка: '.$url.' · Подтверждение email произойдёт после принятия приглашения.')
                                ->persistent()->send();
                        } catch (\Illuminate\Validation\ValidationException $e) {
                            throw $e;
                        } catch (Throwable $e) {
                            report($e);
                            Notification::make()->danger()->title('Не удалось отправить приглашение')
                                ->body('Проверьте почтовый транспорт. Если запись появилась в списке, обновите ссылку и отправьте её повторно.')
                                ->send();
                        }
                    }),
            ])
            ->recordActions([
                Action::make('details')->label('Прогресс и история')
                    ->icon('heroicon-o-clock')
                    ->modalHeading(fn (AccountInvitation $record): string => 'Приглашение #'.$record->id)
                    ->modalSubmitAction(false)
                    ->modalContent(fn (AccountInvitation $record) => view(
                        'filament.backoffice.account-invitation-history',
                        ['invitation' => $record->load(['events', 'acceptedUser', 'issuer'])],
                    ))
                    ->extraModalFooterActions(fn (AccountInvitation $record): array => [
                        self::renewAction('renewFromDetails')->record($record)->cancelParentActions(),
                        self::revokeAction('revokeFromDetails')->record($record)->cancelParentActions(),
                        self::restoreAction('restoreFromDetails')->record($record)->cancelParentActions(),
                    ]),
                self::renewAction(),
                self::revokeAction(),
                self::restoreAction(),
            ])
            ->defaultSort('id', 'desc');
    }

    private static function renewAction(string $name = 'renew'): Action
    {
        return Action::make($name)->label('Продлить / обновить ссылку')
            ->icon('heroicon-o-arrow-path')
            ->visible(fn (AccountInvitation $record): bool => $record->canManageLink())
            ->schema([
                Select::make('days')->label('Новый срок')->required()->default(7)
                    ->options([1 => '1 день', 3 => '3 дня', 7 => '7 дней', 14 => '14 дней', 30 => '30 дней']),
            ])
            ->modalDescription('Предыдущая ссылка перестанет действовать. Получателю будет отправлена новая.')
            ->action(function (AccountInvitation $record, array $data): void {
                abort_unless(self::canViewAny(), 403);
                try {
                    app(AccountInvitationManager::class)->renew($record, auth('web')->user(), (int) $data['days']);
                    Notification::make()->success()->title('Новая ссылка отправлена')->send();
                } catch (\Illuminate\Validation\ValidationException $e) {
                    throw $e;
                } catch (Throwable $e) {
                    report($e);
                    Notification::make()->danger()->title('Не удалось отправить новую ссылку')
                        ->body('Обновите список и проверьте состояние приглашения.')->send();
                }
            });
    }

    private static function revokeAction(string $name = 'revoke'): Action
    {
        return Action::make($name)->label('Аннулировать')->color('danger')
            ->visible(fn (AccountInvitation $record): bool => $record->canManageLink())
            ->requiresConfirmation()
            ->action(function (AccountInvitation $record): void {
                app(AccountInvitationManager::class)->revoke($record, auth('web')->user());
                Notification::make()->success()->title('Приглашение аннулировано')->send();
            });
    }

    private static function restoreAction(string $name = 'restore'): Action
    {
        return Action::make($name)->label('Восстановить')->icon('heroicon-o-arrow-path')
            ->visible(fn (AccountInvitation $record): bool => $record->canRestoreLink())
            ->modalDescription('Приглашение снова станет активным. Получателю будет отправлена новая ссылка.')
            ->schema([
                Select::make('days')->label('Новый срок')->required()->default(7)
                    ->options([1 => '1 день', 3 => '3 дня', 7 => '7 дней', 14 => '14 дней', 30 => '30 дней']),
                Textarea::make('reason')->label('Причина восстановления')->required()->minLength(5)->maxLength(2000)->rows(3),
            ])
            ->action(function (AccountInvitation $record, array $data): void {
                abort_unless(self::canViewAny(), 403);
                try {
                    app(AccountInvitationManager::class)->restore(
                        $record, auth('web')->user(), (int) $data['days'], (string) $data['reason']
                    );
                    Notification::make()->success()->title('Приглашение восстановлено, новая ссылка отправлена')->send();
                } catch (\Illuminate\Validation\ValidationException $e) {
                    throw $e;
                } catch (Throwable $e) {
                    report($e);
                    Notification::make()->danger()->title('Не удалось отправить новую ссылку')
                        ->body('Обновите список и проверьте состояние приглашения.')->send();
                }
            });
    }

    public static function getPages(): array
    {
        return ['index' => ListAccountInvitations::route('/')];
    }
}
