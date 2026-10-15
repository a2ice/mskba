<?php

namespace App\Filament\Backoffice\Resources\AccountDashboardWidgets;

use App\Filament\Backoffice\Resources\AccountDashboardWidgets\Pages\CreateAccountDashboardWidget;
use App\Filament\Backoffice\Resources\AccountDashboardWidgets\Pages\EditAccountDashboardWidget;
use App\Filament\Backoffice\Resources\AccountDashboardWidgets\Pages\ListAccountDashboardWidgets;
use App\Modules\Dashboard\Application\Rules\DashboardWidgetLinkRule;
use App\Modules\Dashboard\Application\Services\AccountDashboardService;
use App\Modules\Dashboard\Application\Services\DashboardWidgetActions;
use App\Modules\Dashboard\Domain\Models\AccountDashboardWidget;
use App\Modules\Dashboard\Domain\Models\DashboardButtonStyle;
use App\Modules\Identity\Application\Services\BackofficePermissionManager;
use App\Modules\Identity\Domain\Enums\UserOperationalPermissionEnum as BackofficePermission;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleEnum;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\CodeEditor;
use Filament\Forms\Components\CodeEditor\Enums\Language;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

final class AccountDashboardWidgetResource extends Resource
{
    protected static ?string $model = AccountDashboardWidget::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-squares-2x2';

    protected static ?int $navigationSort = 1;

    public static function getNavigationLabel(): string
    {
        return 'Виджеты дашборда';
    }

    public static function getModelLabel(): string
    {
        return 'виджет';
    }

    public static function getPluralModelLabel(): string
    {
        return 'виджеты дашборда';
    }

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

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        if (! self::isSuperadmin()) {
            $query->where('kind', '!=', 'custom_html');
        }

        return $query;
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
                        CodeEditor::make('body')->label('HTML-содержимое')->language(Language::Html)
                            ->columnSpanFull()
                            ->helperText('Текст с безопасной HTML-разметкой: <p>, <strong>, <ul>, <li>. Для счётчика сообщений: {{ unread_count }}. Кнопки настраиваются во вкладке «Действия». Пустое поле — стандартный текст.')
                            ->visible(fn (Get $get): bool => in_array($get('kind'), [
                                'information', 'advertisement', 'getting_started', 'message_summary',
                            ], true)),
                        Section::make('Тексты для разных статусов аккаунта')
                            ->description('Каждое состояние имеет собственное содержание. Пустое поле оставит стандартный текст. Статус и системную кнопку подтверждения портал сохраняет.')
                            ->columnSpanFull()
                            ->visible(fn (Get $get): bool => $get('kind') === 'account_status')
                            ->schema([
                                CodeEditor::make('status_confirmed_html')->label('Аккаунт подтверждён')
                                    ->language(Language::Html)
                                    ->helperText('По умолчанию: Аккаунт подтверждён. Теперь доступны возможности портала, для которых требуется подтверждение аккаунта.'),
                                CodeEditor::make('status_unconfirmed_html')->label('Аккаунт не подтверждён')
                                    ->language(Language::Html)
                                    ->helperText('По умолчанию: Подтверди аккаунт, чтобы получить доступ ко всем возможностям портала.'),
                                CodeEditor::make('status_blocked_html')->label('Аккаунт заблокирован')
                                    ->language(Language::Html)
                                    ->helperText('По умолчанию: Аккаунт заблокирован. Если возникли вопросы, обратись в поддержку.'),
                            ]),
                    ]),
                ]),
                Tab::make('Действия')->icon('heroicon-o-cursor-arrow-rays')
                    ->visible(fn (Get $get): bool => $get('kind') !== 'custom_html')
                    ->schema([
                        Section::make('Кнопки виджета')
                            ->description('Добавляй кнопки, меняй порядок перетаскиванием, указывай ссылку и CSS-классы. Все стандартные кнопки редактируются здесь. Никакого HTML писать не нужно.')
                            ->schema([
                                Repeater::make('actions')->label('Список кнопок')
                                    ->schema([
                                        TextInput::make('label')->label('Надпись на кнопке')
                                            ->required()->maxLength(100)->columnSpan(2),
                                        TextInput::make('url')->label('Ссылка')
                                            ->required()->maxLength(500)
                                            ->rule(new DashboardWidgetLinkRule)
                                            ->placeholder('/account/roles или https://example.com')
                                            ->columnSpan(2),
                                        Select::make('appearance')->label('Стандартное оформление')
                                            ->options(['primary' => 'Основная (оранжевая)', 'secondary' => 'Второстепенная'])
                                            ->default('secondary')->required(),
                                        Select::make('visibility')->label('Когда показывать')
                                            ->options([
                                                'always' => 'Всегда',
                                                'confirmed' => 'Аккаунт подтверждён',
                                                'unconfirmed' => 'Аккаунт не подтверждён',
                                                'blocked' => 'Аккаунт заблокирован',
                                            ])->default('always')->required(),
                                        Select::make('classes')->label('Дополнительные CSS-классы')
                                            ->options(fn (): array => DashboardButtonStyle::query()
                                                ->where('enabled', true)->orderBy('name')
                                                ->pluck('name', 'class_name')->all())
                                            ->multiple()->searchable()->preload()
                                            ->helperText('Стили из раздела «Стили кнопок»; можно комбинировать несколько.')
                                            ->columnSpanFull(),
                                    ])
                                    ->columns(2)->defaultItems(0)->collapsible()->reorderable()
                                    ->maxItems(12)->addActionLabel('Добавить кнопку'),
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
                        ColorPicker::make('gradient_color')->label('Цвет подсветки карточки')
                            ->nullable()->regex('/^#[0-9a-fA-F]{6}$/D')
                            ->helperText('Пустое поле — текущий фирменный оранжевый градиент. Цвет применяется к мягкому свечению в правом нижнем углу, остальной фон не меняется.')
                            ->columnSpanFull(),
                        Toggle::make('enabled')->label('Публиковать')->default(true),
                        Toggle::make('can_hide')->label('Можно скрыть пользователю')->default(true)
                            ->helperText('Обязательные виджеты нельзя будет скрывать в настройках аккаунта.'),
                        TextInput::make('refresh_delay_seconds')
                            ->label('Задержка обновления (секунд)')
                            ->integer()->minValue(0)->maxValue(60)->default(5)->required()
                            ->helperText('0 — мгновенно. По умолчанию 5 с: прежняя версия остаётся видимой с обратным отсчётом; повторное сохранение блокируется на это время.'),
                    ]),
                ]),
                Tab::make('Изображение')->icon('heroicon-o-photo')->schema([
                    Section::make('Изображение виджета')
                        ->description('Можно использовать с любым типом виджета. Отсутствие ссылки скрывает изображение.')
                        ->columns(2)->schema([
                            TextInput::make('image_url')->label('URL изображения')
                                ->url()->rule('url:http,https')->maxLength(500)
                                ->placeholder('https://example.com/image.jpg')->columnSpanFull(),
                            Select::make('image_position')->label('Расположение относительно текста')
                                ->options([
                                    'left' => 'Слева от текста (сетка)',
                                    'right' => 'Справа от текста (сетка)',
                                    'top' => 'Сверху текста',
                                    'bottom' => 'Снизу текста',
                                ])->default('top')->required()->native(false),
                            Select::make('image_width_percent')->label('Ширина изображения')
                                ->options([25 => '25%', 50 => '50%', 75 => '75%', 100 => '100%'])
                                ->default(100)->required()->native(false)
                                ->helperText('В сетке оставшееся пространство отдаётся тексту. При 100% изображение и текст располагаются друг под другом.'),
                            TextInput::make('image_gap_px')->label('Gap (px)')
                                ->integer()->minValue(0)->maxValue(64)->default(10)->required()
                                ->helperText('Расстояние между изображением и текстом (по умолчанию 10 px).'),
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
        $actor = auth('web')->user();
        $permission = $previous === null
            ? BackofficePermission::BACKOFFICE_WIDGET_CREATE
            : BackofficePermission::BACKOFFICE_WIDGET_EDIT;
        $checker = app(BackofficePermissionManager::class);
        abort_unless($actor
            && $checker->allows($actor, BackofficePermission::BACKOFFICE_ACCESS)
            && $checker->allows($actor, BackofficePermission::BACKOFFICE_WIDGET_VIEW)
            && $checker->allows($actor, $permission), 403);
        abort_if(($data['kind'] ?? $previous?->kind) === 'custom_html' && ! self::isSuperadmin(), 403);
        abort_if($previous?->kind === 'custom_html' && ! self::isSuperadmin(), 403);

        $kind = (string) ($data['kind'] ?? $previous?->kind ?? '');
        $actionsService = app(DashboardWidgetActions::class);
        $actions = $data['actions'] ?? [];
        if ($kind === 'custom_html') {
            $data['actions'] = [];
        } else {
            if (! is_array($actions)) {
                throw ValidationException::withMessages([
                    'data.actions' => 'Некорректный список действий.',
                ]);
            }

            $data['actions'] = $actionsService->validate($actions, $kind);
            // Deprecated columns remain populated for integrations and the
            // legacy editor, but Filament's only source of truth is Actions.
            $data['link_url'] = $data['actions'][0]['url'] ?? null;
            $data['link_label'] = $data['actions'][0]['label'] ?? null;
        }

        $data['image_position'] ??= $previous?->image_position ?? 'top';
        $data['image_width_percent'] ??= $previous?->image_width_percent ?? 100;
        $data['image_gap_px'] ??= $previous?->image_gap_px ?? 10;
        $data['refresh_delay_seconds'] ??= $previous?->refresh_delay_seconds ?? 5;
        if (! in_array($data['image_position'], ['left', 'right', 'top', 'bottom'], true)
            || ! in_array((int) $data['image_width_percent'], [25, 50, 75, 100], true)
            || filter_var($data['image_gap_px'], FILTER_VALIDATE_INT) === false
            || (int) $data['image_gap_px'] < 0 || (int) $data['image_gap_px'] > 64
            || filter_var($data['refresh_delay_seconds'], FILTER_VALIDATE_INT) === false
            || (int) $data['refresh_delay_seconds'] < 0 || (int) $data['refresh_delay_seconds'] > 60) {
            throw ValidationException::withMessages([
                'data.image_position' => 'Проверь расположение, ширину, отступ и задержку обновления виджета.',
            ]);
        }
        if (filled($data['image_url'] ?? null)
            && (! filter_var($data['image_url'], FILTER_VALIDATE_URL)
                || ! in_array(parse_url($data['image_url'], PHP_URL_SCHEME), ['http', 'https'], true))) {
            throw ValidationException::withMessages([
                'data.image_url' => 'Нужен абсолютный HTTP/HTTPS URL изображения.',
            ]);
        }

        if (filled($data['link_url'] ?? null)
            && ! DashboardWidgetLinkRule::isValid((string) $data['link_url'])) {
            throw ValidationException::withMessages([
                'data.link_url' => 'Допустимы только внутренние ссылки /... или внешние HTTPS/HTTP адреса.',
            ]);
        }

        if (filled($data['gradient_color'] ?? null)
            && ! preg_match('/^#[0-9a-fA-F]{6}$/D', (string) $data['gradient_color'])) {
            throw ValidationException::withMessages([
                'data.gradient_color' => 'Цвет градиента должен быть в формате #RRGGBB.',
            ]);
        }

        foreach (['body', 'status_confirmed_html', 'status_unconfirmed_html', 'status_blocked_html'] as $field) {
            if (mb_strlen((string) ($data[$field] ?? '')) > 10000) {
                throw ValidationException::withMessages(["data.$field" => 'Максимальная длина HTML — 10 000 символов.']);
            }
        }

        foreach (['include_user_ids', 'exclude_user_ids'] as $key) {
            if (count($data[$key] ?? []) > 200) {
                throw ValidationException::withMessages(["data.$key" => 'Максимум 200 ID.']);
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
