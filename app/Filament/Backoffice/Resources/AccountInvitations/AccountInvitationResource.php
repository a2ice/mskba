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
            ->with(['issuer', 'acceptedUser']);
    }

    public static function table(Table $table): Table
    {
        return $table
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
                TextColumn::make('status')->label('Статус')->badge()
                    ->formatStateUsing(fn ($state, AccountInvitation $record): string => $record->statusLabel()),
                TextColumn::make('progress')->label('Этап')
                    ->formatStateUsing(fn ($state, AccountInvitation $record): string => $record->progressLabel()),
                TextColumn::make('views_count')->label('Просмотры'),
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
                        TextInput::make('title')->label('Название приглашения')->required()->maxLength(160)
                            ->default('Приглашение в MSKBA'),
                        Textarea::make('description')->label('Описание для получателя')
                            ->maxLength(2000)->rows(3),
                        Select::make('days')->label('Срок действия ссылки')->required()->default(7)
                            ->options([1 => '1 день', 3 => '3 дня', 7 => '7 дней', 14 => '14 дней', 30 => '30 дней']),
                    ])
                    ->action(function (array $data): void {
                        abort_unless(self::canViewAny(), 403);
                        try {
                            [$invitation, $url] = app(AccountInvitationManager::class)->create(
                                auth('web')->user(), (string) $data['email'], 'venue',
                                (int) $data['scope_id'], (string) $data['role'],
                                (string) $data['title'], $data['description'] ?? null, (int) $data['days']
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
                    )),
                Action::make('renew')->label('Обновить ссылку')->icon('heroicon-o-arrow-path')
                    ->visible(fn (AccountInvitation $record): bool => $record->status === 'pending')
                    ->schema([
                        Select::make('days')->label('Новый срок')->required()->default(7)
                            ->options([1 => '1 день', 3 => '3 дня', 7 => '7 дней', 14 => '14 дней', 30 => '30 дней']),
                    ])
                    ->requiresConfirmation()
                    ->action(function (AccountInvitation $record, array $data): void {
                        abort_unless(self::canViewAny(), 403);
                        try {
                            $url = app(AccountInvitationManager::class)->renew($record, auth('web')->user(), (int) $data['days']);
                            Notification::make()->success()->title('Новая ссылка отправлена')
                                ->body('Старая ссылка недействительна. Новая: '.$url)
                                ->persistent()->send();
                        } catch (Throwable $e) {
                            report($e);
                            Notification::make()->danger()->title('Не удалось отправить новую ссылку')->send();
                        }
                    }),
                Action::make('revoke')->label('Отозвать')->color('danger')
                    ->visible(fn (AccountInvitation $record): bool => $record->status === 'pending')
                    ->requiresConfirmation()
                    ->action(function (AccountInvitation $record): void {
                        app(AccountInvitationManager::class)->revoke($record, auth('web')->user());
                        Notification::make()->success()->title('Приглашение отозвано')->send();
                    }),
            ])
            ->defaultSort('id', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ListAccountInvitations::route('/')];
    }
}
