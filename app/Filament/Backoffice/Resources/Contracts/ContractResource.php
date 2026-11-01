<?php

namespace App\Filament\Backoffice\Resources\Contracts;

use App\Filament\Backoffice\Resources\Contracts\Pages\EditContract;
use App\Filament\Backoffice\Resources\Contracts\Pages\ListContracts;
use App\Modules\Contract\Application\Services\BackofficeContractEditor;
use App\Modules\Contract\Domain\Enums\ContractFamilyEnum;
use App\Modules\Contract\Domain\Enums\ContractMembershipScopeTypeEnum as Scope;
use App\Modules\Contract\Domain\Enums\ContractStatusEnum;
use App\Modules\Contract\Domain\Models\Contract;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final class ContractResource extends Resource
{
    protected static ?string $model = Contract::class;
    protected static ?string $recordTitleAttribute = 'name';
    protected static string|\UnitEnum|null $navigationGroup = 'Контент';
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-document-text';
    protected static ?int $navigationSort = 10;

    public static function getNavigationLabel(): string { return 'Контракты'; }
    public static function getModelLabel(): string { return 'контракт'; }
    public static function getPluralModelLabel(): string { return 'контракты'; }

    public static function canViewAny(): bool
    {
        return app(BackofficeContractEditor::class)->canView();
    }

    public static function canView(Model $record): bool
    {
        return $record instanceof Contract && self::canViewAny();
    }

    public static function canEdit(Model $record): bool
    {
        return $record instanceof Contract && app(BackofficeContractEditor::class)->canManage();
    }

    public static function canCreate(): bool { return false; }
    public static function canDelete(Model $record): bool { return false; }
    public static function canDeleteAny(): bool { return false; }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['membership.user', 'permissions']);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Tabs::make('Контракт')->persistTabInQueryString()->tabs([
                Tab::make('Условия')->icon('heroicon-o-document-text')->schema([
                    Section::make('Стороны')->columns(2)->schema([
                        Placeholder::make('recipient')->label('Пользователь')
                            ->content(fn (?Contract $record): string => $record?->membership?->user?->username ?? '—'),
                        Placeholder::make('scope')->label('Объект')
                            ->content(fn (?Contract $record): string =>
                                $record?->membership?->scope_type?->label().' #'.$record?->membership?->scope_id),
                        Placeholder::make('level')->label('Роль')
                            ->content(fn (?Contract $record): string =>
                                $record?->membership?->access_level ?? '—'),
                        Placeholder::make('invitation')->label('Подтверждение')
                            ->content(fn (?Contract $record): string => match ($record?->membership?->invitation_status?->value) {
                                'accepted' => 'Принят', 'pending' => 'Ожидает подтверждения',
                                'declined' => 'Отклонён', 'revoked' => 'Отозван', default => '—',
                            }),
                    ]),
                    Section::make('Условия договора')->columns(2)->schema([
                        TextInput::make('name')->label('Название')->required()->minLength(2)->maxLength(150)->columnSpanFull(),
                        Select::make('status')->label('Статус')->required()
                            ->options(collect(ContractStatusEnum::cases())->mapWithKeys(
                                fn ($v): array => [$v->value => $v->label()])->all())
                            ->disabled(fn (?Contract $record): bool => $record === null
                                || app(BackofficeContractEditor::class)->isOwnerContract($record))
                            ->dehydrated(),
                        DateTimePicker::make('expires_at')->label('Действует до')
                            ->seconds(false)->native(false)
                            ->disabled(fn (?Contract $record): bool => $record === null
                                || app(BackofficeContractEditor::class)->isOwnerContract($record)),
                        Textarea::make('notes')->label('Комментарий')->maxLength(2000)->rows(3)->columnSpanFull(),
                        Textarea::make('change_reason')->label('Причина изменения')
                            ->required()->minLength(5)->maxLength(2000)->rows(2)
                            ->helperText('Сохраняется в истории изменений и не заменяет комментарий к контракту.')
                            ->columnSpanFull(),
                    ]),
                ]),
                Tab::make('Полномочия')->icon('heroicon-o-shield-check')->schema([
                    Section::make('Разрешения')->schema([
                        CheckboxList::make('permissions')->label('Доступные действия')
                            ->options(fn (?Contract $record): array => $record === null
                                ? [] : app(BackofficeContractEditor::class)->permissionOptions($record))
                            ->columns(2)
                            ->disabled(fn (?Contract $record): bool => $record === null
                                || ! app(BackofficeContractEditor::class)->mayEditRights($record))
                            ->dehydrated(fn (?Contract $record): bool => $record !== null
                                && app(BackofficeContractEditor::class)->mayEditRights($record)),
                        Placeholder::make('rights_hint')->label('')
                            ->content(fn (?Contract $record): string =>
                                $record !== null && app(BackofficeContractEditor::class)->mayEditRights($record)
                                ? 'Расширение полномочий требует повторного подтверждения получателем.'
                                : 'Права владельца и специальные договоры изменяются в профильном порядке.'),
                    ]),
                ]),
                Tab::make('История изменений')->icon('heroicon-o-clock')->schema([
                    View::make('filament.backoffice.contract-history'),
                ]),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('id')->label('№')->sortable(),
            TextColumn::make('name')->label('Контракт')->searchable()->sortable(),
            TextColumn::make('membership.user.username')->label('Пользователь')->searchable(),
            TextColumn::make('membership.scope_type')->label('Сущность')
                ->state(fn (Contract $record): string => $record->membership?->scope_type?->label() ?? 'Другой тип'),
            TextColumn::make('membership.scope_id')->label('ID объекта'),
            TextColumn::make('membership.access_level')->label('Роль'),
            TextColumn::make('status')->label('Статус')->badge()
                ->formatStateUsing(fn (ContractStatusEnum $state): string => $state->label()),
            TextColumn::make('expires_at')->label('Действует до')->dateTime('d.m.Y H:i')->placeholder('Бессрочно'),
            TextColumn::make('created_at')->label('Создан')->dateTime('d.m.Y')->sortable(),
        ])->defaultSort('id', 'desc')->filters([
            SelectFilter::make('status')->label('Статус')
                ->options(collect(ContractStatusEnum::cases())->mapWithKeys(
                    fn ($v): array => [$v->value => $v->label()])->all()),
            SelectFilter::make('family')->label('Тип')
                ->options(collect(ContractFamilyEnum::cases())->mapWithKeys(
                    fn ($v): array => [$v->value => $v->label()])->all()),
            SelectFilter::make('scope_type')->label('Сущность')
                ->options(collect(Scope::cases())->mapWithKeys(
                    fn (Scope $v): array => [$v->value => $v->label()])->all())
                ->query(fn (Builder $query, array $data): Builder => $query
                    ->when($data['value'] ?? null, fn (Builder $q, string $scope) =>
                        $q->whereHas('membership', fn (Builder $m) => $m->where('scope_type', $scope)))),
            Filter::make('user')->label('Пользователь')->form([
                TextInput::make('username')->label('Логин'),
            ])->query(fn (Builder $query, array $data): Builder =>
                $query->when($data['username'] ?? null, fn (Builder $q, string $username) =>
                    $q->whereHas('membership.user', fn (Builder $u) =>
                        $u->whereLike('username', '%'.$username.'%')))),
            Filter::make('entity')->label('ID объекта')->form([
                TextInput::make('scope_id')->label('ID объекта')->integer()->minValue(1),
            ])->query(fn (Builder $query, array $data): Builder =>
                $query->when($data['scope_id'] ?? null, fn (Builder $q, $id) =>
                    $q->whereHas('membership', fn (Builder $m) =>
                        $m->where('scope_id', (int) $id)))),
        ])->recordActions([
            EditAction::make()->label('Открыть')
                ->visible(fn (Contract $record): bool => self::canEdit($record)),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListContracts::route('/'),
            'edit' => EditContract::route('/{record}/edit'),
        ];
    }
}
