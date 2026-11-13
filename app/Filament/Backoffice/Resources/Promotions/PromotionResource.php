<?php

namespace App\Filament\Backoffice\Resources\Promotions;

use App\Filament\Backoffice\Resources\Promotions\Pages\CreatePromotion;
use App\Filament\Backoffice\Resources\Promotions\Pages\EditPromotion;
use App\Filament\Backoffice\Resources\Promotions\Pages\ListPromotions;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Promotions\Domain\Enums\PromotionType;
use App\Modules\Promotions\Domain\Models\Promotion;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

final class PromotionResource extends Resource
{
    protected static ?string $model = Promotion::class;
    protected static string|\UnitEnum|null $navigationGroup = 'Контент';
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-megaphone';
    protected static ?int $navigationSort = 10;

    public static function getNavigationLabel(): string { return 'Акции'; }
    public static function getModelLabel(): string { return 'акция'; }
    public static function getPluralModelLabel(): string { return 'Акции'; }

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
    public static function canDelete(Model $record): bool { return self::superadmin(); }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Содержание акции')
                ->description('Публично показываем акцию, а внутреннее вознаграждение определяет начисление. Привязка к Reward не включает отключённый механизм.')
                ->columns(2)
                ->schema([
                    TextInput::make('code')->label('Системный код')
                        ->required()->maxLength(96)->regex('/\A[a-z0-9][a-z0-9_]*\z/D')
                        ->unique(ignoreRecord: true)->disabledOn('edit'),
                    Select::make('type')->label('Тип акции')
                        ->options([
                            PromotionType::REWARDABLE->value => PromotionType::REWARDABLE->label(),
                            PromotionType::SIMPLE->value => PromotionType::SIMPLE->label(),
                            PromotionType::MULTI_REWARDABLE->value => PromotionType::MULTI_REWARDABLE->label(),
                        ])
                        ->disableOptionWhen(fn (string $value): bool => $value === PromotionType::MULTI_REWARDABLE->value)
                        ->default(PromotionType::REWARDABLE->value)
                        ->required()->live()->native(false),
                    Select::make('reward_id')->label('Системное вознаграждение')
                        ->relationship('reward', 'name')
                        ->searchable()->preload()->native(false)
                        ->required(fn (Get $get): bool => $get('type') === PromotionType::REWARDABLE->value)
                        ->visible(fn (Get $get): bool => $get('type') === PromotionType::REWARDABLE->value)
                        ->helperText('Один Reward на одну акцию. Для нового действия сначала подключить соответствующий механизм Rewards.'),
                    Toggle::make('is_enabled')->label('Акция включена')->default(false),
                    TextInput::make('title')->label('Заголовок')->required()->maxLength(200)->columnSpanFull(),
                    Textarea::make('description')->label('Основное описание')->rows(3)->columnSpanFull(),
                    TextInput::make('cta_label')->label('Текст кнопки')->maxLength(120),
                    TextInput::make('cta_url')->label('Внутренняя ссылка')->maxLength(1000)
                        ->regex('/\A\/(?!\/)[^\s]*\z/D')
                        ->placeholder('/venues')
                        ->helperText('Только внутренний путь, начинающийся с /; для приглашений и добавления площадки работают штатные действия.'),
                ]),
            Section::make('Сроки')
                ->description('Если обе даты пустые, акция бессрочная. Статус вознаграждения проверяется отдельно.')
                ->columns(2)
                ->schema([
                    \Filament\Forms\Components\DateTimePicker::make('starts_at')->label('Начало'),
                    \Filament\Forms\Components\DateTimePicker::make('ends_at')->label('Окончание')->after('starts_at'),
                ]),
            Section::make('Места показа')
                ->description('Показываем только в выбранных местах. Пустые локальные значения наследуют общий заголовок и описание.')
                ->schema([
                    Repeater::make('placements')->label('Размещения')
                        ->relationship()
                        ->schema([
                            Select::make('placement_code')->label('Место')
                                ->options([
                                    'avatar_topup' => 'Генерация аватара: Как пополнить баланс',
                                    'account_wallet' => 'Личный кабинет: Кошелёк',
                                    'venue_page' => 'Страница площадки',
                                    'venue_slots' => 'Попап выбора слотов',
                                ])
                                ->required()->distinct()->native(false),
                            TextInput::make('title_override')->label('Заголовок для этого места')
                                ->maxLength(200)->placeholder('Наследуется от акции'),
                            Textarea::make('description_override')->label('Описание для этого места')
                                ->rows(2)->columnSpanFull()
                                ->placeholder('Наследуется от акции'),
                        ])
                        ->columns(2)->columnSpanFull()->defaultItems(0)->collapsible()
                        ->addActionLabel('Добавить место показа'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')->columns([
            TextColumn::make('title')->label('Название')->searchable(),
            TextColumn::make('code')->label('Код')->copyable(),
            TextColumn::make('type')->label('Тип')->formatStateUsing(
                fn (?PromotionType $state): string => $state?->label() ?? '—'
            ),
            TextColumn::make('reward.name')->label('Вознаграждение')->placeholder('Без вознаграждения'),
            IconColumn::make('is_enabled')->label('Включена')->boolean(),
            TextColumn::make('ends_at')->label('Окончание')->dateTime('d.m.Y H:i')->placeholder('Бессрочно'),
        ])->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPromotions::route('/'),
            'create' => CreatePromotion::route('/create'),
            'edit' => EditPromotion::route('/{record}/edit'),
        ];
    }
}
