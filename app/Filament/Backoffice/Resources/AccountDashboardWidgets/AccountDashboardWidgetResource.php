<?php

namespace App\Filament\Backoffice\Resources\AccountDashboardWidgets;

use App\Filament\Backoffice\Resources\AccountDashboardWidgets\Pages\CreateAccountDashboardWidget;
use App\Filament\Backoffice\Resources\AccountDashboardWidgets\Pages\EditAccountDashboardWidget;
use App\Filament\Backoffice\Resources\AccountDashboardWidgets\Pages\ListAccountDashboardWidgets;
use App\Modules\Dashboard\Application\Services\AccountDashboardService;
use App\Modules\Dashboard\Domain\Models\AccountDashboardWidget;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleEnum;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

final class AccountDashboardWidgetResource extends Resource
{
    protected static ?string $model = AccountDashboardWidget::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-squares-2x2';

    protected static ?int $navigationSort = 1;

    public static function getNavigationLabel(): string { return 'Виджеты дашборда'; }
    public static function getModelLabel(): string { return 'виджет'; }
    public static function getPluralModelLabel(): string { return 'виджеты дашборда'; }

    private static function isSuperadmin(): bool
    {
        $user = auth('web')->user();

        return $user !== null
            && $user->canonical()->isConfirmed()
            && $user->canonical()->system_role === UserSystemRoleEnum::SUPERADMIN;
    }

    private static function options(array $cases): array
    {
        return collect($cases)->mapWithKeys(fn ($role) => [$role->value => $role->label()])->all();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Tabs::make('Параметры виджета')->tabs([
                Tab::make('Содержимое')->icon('heroicon-o-document-text')->schema([
                    Section::make('Основные сведения')->columns(2)->schema([
                        Select::make('kind')->label('Тип')
                            ->options(fn (): array => collect(AccountDashboardService::KINDS)
                                ->except(self::isSuperadmin() ? [] : ['custom_html'])->all())
                            ->required()->live()->native(false)
                            ->helperText('Функциональные карточки подключены к данным портала. Реклама и информация управляются контентом.'),
                        TextInput::make('title')->label('Название')->required()->maxLength(150),
                        Textarea::make('body')->label('Содержимое')->rows(4)->maxLength(10000)
                            ->columnSpanFull()
                            ->visible(fn (Get $get): bool => in_array($get('kind'), ['information', 'advertisement'], true)),
                        TextInput::make('link_url')->label('Ссылка')->url()->rule('url:http,https')->maxLength(500)
                            ->required(fn (Get $get): bool => $get('kind') === 'advertisement')
                            ->helperText('HTTPS предпочтительнее. Реклама обязательно содержит ссылку.')
                            ->visible(fn (Get $get): bool => in_array($get('kind'), ['information', 'advertisement'], true)),
                        TextInput::make('link_label')->label('Текст кнопки')->maxLength(100)
                            ->visible(fn (Get $get): bool => in_array($get('kind'), ['information', 'advertisement'], true)),
                        TextInput::make('image_url')->label('Изображение рекламы')->url()->rule('url:http,https')
                            ->maxLength(500)->columnSpanFull()->placeholder('https://example.com/banner.jpg')
                            ->visible(fn (Get $get): bool => $get('kind') === 'advertisement'),
                    ]),
                ]),
                Tab::make('Аудитория')->icon('heroicon-o-user-group')->schema([
                    Section::make('Кому показывать')->description('Пустое условие означает всех. Между типами условий — И, внутри выбранных ролей — ИЛИ.')->columns(2)->schema([
                        CheckboxList::make('account_statuses')->label('Статусы аккаунта')
                            ->options(self::options(UserStatusEnum::cases()))->columns(2),
                        Select::make('contact_filter')->label('Основной контакт')
                            ->options(['any' => 'Любой', 'verified' => 'Подтверждён',
                                'unverified' => 'Не подтверждён'])->default('any')->required(),
                        CheckboxList::make('system_roles')->label('Системные роли')
                            ->options(self::options(UserSystemRoleEnum::cases()))->columns(2)->columnSpanFull(),
                        CheckboxList::make('participation_roles')->label('Роли участия')
                            ->options(self::options(UserParticipationRoleEnum::cases()))->columns(2)->columnSpanFull(),
                    ]),
                    Section::make('Точечный показ')->description('Исключение имеет высший приоритет. Явное включение обходит фильтры выше.')->columns(2)->schema([
                        TextInput::make('include_user_ids')->label('Показать этим пользователям')
                            ->placeholder('12, 15, 42')->helperText('ID через запятую, не более 200')
                            ->regex('/^\d+(?:[\s,]+\d+)*$/')
                            ->formatStateUsing(fn ($state): string => implode(', ', (array) $state))
                            ->dehydrateStateUsing(fn ($state): array => self::parseIds($state)),
                        TextInput::make('exclude_user_ids')->label('Никогда не показывать этим пользователям')
                            ->placeholder('8, 16')->helperText('Исключения имеют высший приоритет')
                            ->regex('/^\d+(?:[\s,]+\d+)*$/')
                            ->formatStateUsing(fn ($state): string => implode(', ', (array) $state))
                            ->dehydrateStateUsing(fn ($state): array => self::parseIds($state)),
                    ]),
                ]),
                Tab::make('Размещение')->icon('heroicon-o-adjustments-horizontal')->schema([
                    Section::make('Отображение')->columns(2)->schema([
                        TextInput::make('sort_order')->label('Порядок')->integer()
                            ->minValue(0)->maxValue(9999)->default(100)->required(),
                        Select::make('size')->label('Ширина карточки')->options([
                            'half' => 'Половина ряда', 'full' => 'На всю ширину',
                        ])->default('half')->required(),
                        Toggle::make('enabled')->label('Публиковать')->default(true),
                        Toggle::make('can_hide')->label('Можно скрыть пользователю')->default(true)
                            ->helperText('Обязательные виджеты нельзя будет скрывать в настройках аккаунта.'),
                    ]),
                ]),
                Tab::make('HTML / CSS / JS')->icon('heroicon-o-code-bracket')
                    ->visible(fn (): bool => self::isSuperadmin())
                    ->schema([
                        Section::make('Исполняемый виджет')->description('Только суперадминистратор. Код работает в sandbox iframe без сессии портала.')->schema([
                            Textarea::make('html_code')->label('HTML')->rows(10)->maxLength(40000)
                                ->visible(fn (Get $get): bool => $get('kind') === 'custom_html'),
                            Textarea::make('css_code')->label('CSS')->rows(8)->maxLength(20000)
                                ->visible(fn (Get $get): bool => $get('kind') === 'custom_html'),
                            Textarea::make('js_code')->label('JavaScript')->rows(8)->maxLength(20000)
                                ->visible(fn (Get $get): bool => $get('kind') === 'custom_html'),
                            TextInput::make('iframe_height')->label('Высота (px)')->integer()
                                ->minValue(120)->maxValue(900)->default(260)->required()
                                ->visible(fn (Get $get): bool => $get('kind') === 'custom_html'),
                        ]),
                    ]),
            ])->columnSpanFull(),
        ]);
    }

    public static function parseIds(mixed $raw): array
    {
        $ids = preg_split('/[\s,]+/', trim((string) $raw), -1, PREG_SPLIT_NO_EMPTY);

        return array_values(array_unique(array_map('intval', $ids ?: [])));
    }

    public static function assertSafeData(array &$data, ?AccountDashboardWidget $previous = null): void
    {
        abort_unless(auth('web')->user()?->canonical()->isAdmin(), 403);
        abort_if(($data['kind'] ?? $previous?->kind) === 'custom_html' && ! self::isSuperadmin(), 403);
        abort_if($previous?->kind === 'custom_html' && ! self::isSuperadmin(), 403);

        if (($data['kind'] ?? null) === 'advertisement' && blank($data['link_url'] ?? null)) {
            throw \Illuminate\Validation\ValidationException::withMessages(['data.link_url' => 'Для рекламы нужна ссылка.']);
        }

        foreach (['include_user_ids', 'exclude_user_ids'] as $key) {
            if (count($data[$key] ?? []) > 200) {
                throw \Illuminate\Validation\ValidationException::withMessages(["data.$key" => 'Максимум 200 ID.']);
            }
        }

        if (($data['kind'] ?? null) !== 'custom_html') {
            $data['html_code'] = null;
            $data['css_code'] = null;
            $data['js_code'] = null;
        }
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->columns([
                TextColumn::make('sort_order')->label('№')->sortable(),
                TextColumn::make('title')->label('Виджет')->searchable()->weight('bold')->limit(60),
                TextColumn::make('kind')->label('Тип')->badge()
                    ->formatStateUsing(fn (string $state): string => AccountDashboardService::KINDS[$state] ?? $state),
                IconColumn::make('enabled')->label('Активен')->boolean(),
                IconColumn::make('can_hide')->label('Можно скрыть')->boolean(),
                TextColumn::make('updated_at')->label('Обновлён')->dateTime('d.m.Y H:i')->sortable(),
            ])
            ->filters([
                TernaryFilter::make('enabled')->label('Активные'),
                SelectFilter::make('kind')->label('Тип')->options(AccountDashboardService::KINDS),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAccountDashboardWidgets::route('/'),
            'create' => CreateAccountDashboardWidget::route('/create'),
            'edit' => EditAccountDashboardWidget::route('/{record}/edit'),
        ];
    }
}
