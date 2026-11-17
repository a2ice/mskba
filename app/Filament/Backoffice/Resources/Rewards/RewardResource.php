<?php

namespace App\Filament\Backoffice\Resources\Rewards;

use App\Filament\Backoffice\Resources\Rewards\Pages\CreateReward;
use App\Filament\Backoffice\Resources\Rewards\Pages\EditReward;
use App\Filament\Backoffice\Resources\Rewards\Pages\ListRewards;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Rewards\Application\Services\RewardMechanismRegistry;
use App\Modules\Rewards\Domain\Models\Reward;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

final class RewardResource extends Resource
{
    protected static ?string $model = Reward::class;
    protected static string|\UnitEnum|null $navigationGroup = 'Контент';
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-gift';
    protected static ?int $navigationSort = 9;

    public static function getNavigationLabel(): string { return 'Вознаграждения'; }
    public static function getModelLabel(): string { return 'вознаграждение'; }
    public static function getPluralModelLabel(): string { return 'Вознаграждения'; }

    private static function superadmin(): bool
    {
        $user = auth('web')->user();

        return $user !== null
            && $user->canonical()->isConfirmed()
            && ! $user->canonical()->isBlocked()
            && $user->canonical()->system_role === UserSystemRoleEnum::SUPERADMIN;
    }

    public static function canViewAny(): bool { return self::superadmin(); }
    public static function canCreate(): bool { return self::superadmin(); }
    public static function canEdit(Model $record): bool { return self::superadmin(); }
    public static function canDelete(Model $record): bool { return false; }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Системное вознаграждение')
                ->description('Здесь настраиваются условия и сумма реального начисления. Рекламные заголовки и места показа редактируются в разделе «Акции».')
                ->columns(2)
                ->schema([
                    TextInput::make('code')->label('Системный код')
                        ->required()->maxLength(96)->regex('/\A[a-z0-9][a-z0-9_]*\z/D')
                        ->unique(ignoreRecord: true)->disabledOn('edit'),
                    TextInput::make('name')->label('Название')->required()->maxLength(160),
                    Select::make('mechanism_code')->label('Механизм начисления')
                        ->options(function (?Reward $record): array {
                            $options = collect(app(RewardMechanismRegistry::class)->all())
                                ->mapWithKeys(fn ($mechanism, $code): array => [
                                    $code => $mechanism->label().' ('.$code.')',
                                ])->all();
                            if ($record?->mechanism_code && ! isset($options[$record->mechanism_code])) {
                                $options[$record->mechanism_code] = $record->mechanism_code.' (не подключён)';
                            }
                            return $options;
                        })
                        ->searchable()->native(false)
                        ->helperText('Включить начисление можно только с зарегистрированным механизмом.'),
                    Toggle::make('is_enabled')->label('Начисление включено')->default(false),
                    TextInput::make('amount_rub')->label('Сумма начисления, бонусных ₽')
                        ->required()->numeric()->minValue(0.01)->maxValue(10000000)->step('0.01')
                        ->helperText('Изменение суммы создаёт новую версию финансового правила.'),
                    Toggle::make('real_money_payment')->label('Выплата реальными деньгами')
                        ->disabled()->dehydrated(false)->default(false)
                        ->helperText('Пока недоступно. Начисления производятся только бонусными средствами.'),
                    Textarea::make('description')->label('Внутреннее описание')
                        ->rows(2)->maxLength(4000)->columnSpanFull(),
                    Textarea::make('conditions')->label('Условия начисления')
                        ->rows(2)->maxLength(8000)->columnSpanFull(),
                    Textarea::make('recipient_description')->label('Кому начислять')
                        ->required()->rows(2)->maxLength(4000),
                    Textarea::make('trigger_description')->label('Когда начислять')
                        ->required()->rows(2)->maxLength(4000),
                    Textarea::make('mechanism_parameters_json')->label('Параметры механизма, JSON')
                        ->rows(2)->columnSpanFull()
                        ->helperText('Для механизмов с дополнительными настройками. Пустое поле означает отсутствие параметров.'),
                ]),
            Section::make('Подпись суммы в публичных акциях')
                ->description('Редактируется здесь, а заголовок, основной текст и кнопка — в связанных «Акциях». Текст автоматически учитывает глобальную или индивидуальную сумму площадки.')
                ->schema([
                    TextInput::make('amount_caption_template')->label('Шаблон подписи')
                        ->maxLength(500)
                        ->placeholder('Кешбэк: {amount}')
                        ->helperText('Доступные переменные: {amount} → 100 ₽, {amount_number} → 100, {currency} → ₽. Не вводите фиксированные суммы: они изменятся при переопределении площадки.'),
                ]),
        ]);
    }

    /** @return array<string, mixed> */
    public static function prepareData(array $data): array
    {
        $value = trim((string) ($data['amount_rub'] ?? ''));
        if (! preg_match('/\A\d+(?:\.\d{1,2})?\z/D', $value)) {
            throw ValidationException::withMessages(['data.amount_rub' => 'Укажите положительную сумму с точностью до копеек.']);
        }
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $data['amount_minor'] = ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
        if ($data['amount_minor'] <= 0) {
            throw ValidationException::withMessages(['data.amount_rub' => 'Сумма должна быть больше нуля.']);
        }

        $raw = trim((string) ($data['mechanism_parameters_json'] ?? ''));
        if ($raw === '') {
            $data['mechanism_parameters'] = [];
        } else {
            try {
                $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                throw ValidationException::withMessages(['data.mechanism_parameters_json' => 'Укажите корректный JSON.']);
            }
            if (! is_array($decoded)) {
                throw ValidationException::withMessages(['data.mechanism_parameters_json' => 'JSON должен быть объектом параметров.']);
            }
            $data['mechanism_parameters'] = $decoded;
        }

        $data['currency'] = 'RUB';
        unset($data['amount_rub'], $data['mechanism_parameters_json'], $data['real_money_payment']);

        return $data;
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('id')->columns([
            TextColumn::make('name')->label('Вознаграждение')->searchable(),
            TextColumn::make('code')->label('Код')->copyable(),
            TextColumn::make('mechanism_code')->label('Механизм'),
            TextColumn::make('currentVersion.amount_minor')->label('Бонус')
                ->formatStateUsing(fn ($state): string => $state === null
                    ? '—' : number_format((int) $state / 100, 2, ',', ' ').' ₽'),
            IconColumn::make('is_enabled')->label('Начисление активно')->boolean(),
        ])->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRewards::route('/'),
            'create' => CreateReward::route('/create'),
            'edit' => EditReward::route('/{record}/edit'),
        ];
    }
}
