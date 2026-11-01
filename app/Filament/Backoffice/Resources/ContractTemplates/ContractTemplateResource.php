<?php

namespace App\Filament\Backoffice\Resources\ContractTemplates;

use App\Filament\Backoffice\Resources\ContractTemplates\Pages\CreateContractTemplate;
use App\Filament\Backoffice\Resources\ContractTemplates\Pages\EditContractTemplate;
use App\Filament\Backoffice\Resources\ContractTemplates\Pages\ListContractTemplates;
use App\Modules\Contract\Application\Services\BackofficeContractEditor;
use App\Modules\Contract\Application\Services\EntityContractManager;
use App\Modules\Contract\Domain\Enums\ContractMembershipScopeTypeEnum as Scope;
use App\Modules\Contract\Domain\Models\ContractTemplate;
use App\Modules\Identity\Domain\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

final class ContractTemplateResource extends Resource
{
    protected static ?string $model = ContractTemplate::class;
    protected static ?string $recordTitleAttribute = 'name';
    protected static string|\UnitEnum|null $navigationGroup = 'Контент';
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-bookmark';
    protected static ?int $navigationSort = 11;

    public static function getNavigationLabel(): string { return 'Шаблоны контрактов'; }
    public static function getModelLabel(): string { return 'шаблон контракта'; }
    public static function getPluralModelLabel(): string { return 'шаблоны контрактов'; }

    public static function canViewAny(): bool
    {
        return app(BackofficeContractEditor::class)->canView();
    }

    public static function canView(Model $record): bool
    {
        return $record instanceof ContractTemplate && self::canViewAny();
    }

    public static function canCreate(): bool
    {
        return app(BackofficeContractEditor::class)->canManage();
    }

    public static function canEdit(Model $record): bool
    {
        return $record instanceof ContractTemplate && self::canCreate();
    }

    public static function canDelete(Model $record): bool
    {
        return $record instanceof ContractTemplate && self::canCreate();
    }

    public static function canDeleteAny(): bool { return false; }

    /** @return array<string, string> */
    private static function scopes(): array
    {
        $manager = app(EntityContractManager::class);

        return collect(Scope::cases())
            ->filter(fn (Scope $scope): bool => $manager->allPermissions($scope) !== [])
            ->mapWithKeys(fn (Scope $scope): array => [$scope->value => $scope->label()])
            ->all();
    }

    /** @return array<string, string> */
    private static function rights(?string $scopeValue): array
    {
        $scope = $scopeValue === null ? null : Scope::tryFrom($scopeValue);
        if ($scope === null) return [];

        return collect(app(EntityContractManager::class)->permissionCatalog($scope))
            ->mapWithKeys(static fn (\BackedEnum $permission): array => [
                $permission->value => $permission->label(),
            ])->all();
    }

    public static function validateInput(array &$data): void
    {
        abort_unless(self::canCreate(), 403);

        $scope = Scope::tryFrom((string) ($data['scope_type'] ?? ''));
        $valid = $scope === null ? [] : array_keys(self::rights($scope->value));
        $requested = $data['permissions'] ?? null;
        if ($scope === null || $valid === [] || ! is_array($requested)
            || $requested === [] || count($requested) !== count(array_unique($requested))
            || array_diff($requested, $valid) !== []) {
            throw ValidationException::withMessages([
                'data.permissions' => 'Выберите допустимые разрешения для этого вида сущности.',
            ]);
        }

        if (($data['shared'] ?? false) && (int) ($data['scope_id'] ?? 0) < 1) {
            throw ValidationException::withMessages([
                'data.scope_id' => 'Для общего шаблона выберите объект.',
            ]);
        }
        if (! ($data['shared'] ?? false)) {
            $data['scope_id'] = null;
        }
        if (empty($data['owner_user_id'])) {
            $data['owner_user_id'] = auth('web')->user()?->canonical()->id;
        }
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make('Шаблон контракта')->columns(2)->schema([
                TextInput::make('name')->label('Название')->required()->minLength(2)->maxLength(150)->columnSpanFull(),
                Select::make('scope_type')->label('Для сущности')
                    ->options(self::scopes())->required()->live(),
                Select::make('owner_user_id')->label('Владелец шаблона')->required()
                    ->default(fn (): ?int => auth('web')->user()?->canonical()->id)
                    ->searchable()->getSearchResultsUsing(fn (string $query): array =>
                        User::query()->whereNull('canonical_user_id')
                            ->whereLike('username', '%'.$query.'%')->limit(25)
                            ->pluck('username', 'id')->all())
                    ->getOptionLabelUsing(fn ($value): ?string =>
                        User::query()->whereKey($value)->value('username')),
                Toggle::make('shared')->label('Общий для этой сущности')->default(false)->live(),
                TextInput::make('scope_id')->label('ID объекта')
                    ->numeric()->minValue(1)
                    ->helperText('Укажите для общего шаблона. Личные шаблоны применяются к любому объекту выбранного типа.'),
                CheckboxList::make('permissions')->label('Полномочия')
                    ->options(fn (Get $get): array => self::rights($get('scope_type')))
                    ->columns(2)->required()->columnSpanFull(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->label('Название')->searchable()->sortable(),
            TextColumn::make('scope_type')->label('Сущность')
                ->formatStateUsing(fn (Scope $state): string => $state->label()),
            TextColumn::make('owner.username')->label('Владелец'),
            IconColumn::make('shared')->label('Общий')->boolean(),
            TextColumn::make('scope_id')->label('ID объекта')->placeholder('Любой'),
            TextColumn::make('updated_at')->label('Обновлён')->dateTime('d.m.Y H:i')->sortable(),
        ])->filters([
            SelectFilter::make('scope_type')->label('Сущность')->options(self::scopes()),
        ])->recordActions([EditAction::make(), DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListContractTemplates::route('/'),
            'create' => CreateContractTemplate::route('/create'),
            'edit' => EditContractTemplate::route('/{record}/edit'),
        ];
    }
}
