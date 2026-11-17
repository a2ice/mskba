<?php

namespace App\Filament\Backoffice\Resources\PageBuilder;

use App\Filament\Backoffice\Resources\PageBuilder\Concerns\BuilderPermissions;
use App\Filament\Backoffice\Resources\PageBuilder\Concerns\BuilderPlaceholderGuide;
use App\Filament\Backoffice\Resources\PageBuilder\Pages\CreateBuilderTemplate;
use App\Filament\Backoffice\Resources\PageBuilder\Pages\EditBuilderTemplate;
use App\Filament\Backoffice\Resources\PageBuilder\Pages\ListBuilderTemplates;
use App\Modules\Content\Domain\Models\PageBuilderTemplate;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CodeEditor;
use Filament\Forms\Components\CodeEditor\Enums\Language;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

final class BuilderTemplateResource extends Resource
{
    use BuilderPermissions;

    protected static ?string $model = PageBuilderTemplate::class;
    protected static string|\UnitEnum|null $navigationGroup = 'Сайт';
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-square-3-stack-3d';
    protected static ?int $navigationSort = 5;

    public static function getNavigationLabel(): string { return 'Шаблоны'; }
    public static function getModelLabel(): string { return 'шаблон'; }
    public static function getPluralModelLabel(): string { return 'шаблоны'; }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make('Шаблон')->columns(2)->schema([
                TextInput::make('name')->label('Название')->required()->maxLength(150),
                TextInput::make('slug')->label('Идентификатор')->required()
                    ->regex('/\A[a-z][a-z0-9_-]{1,79}\z/D')->unique(ignoreRecord: true),
            ]),
            Section::make('HTML')->columns(1)->schema([
                CodeEditor::make('draft_html')->label('HTML')->language(Language::Html)
                    ->required()->columnSpanFull(),
            ])->columnSpanFull(),
            BuilderPlaceholderGuide::panel(true)->collapsible()->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->label('Шаблон')->searchable(),
            TextColumn::make('slug')->label('Код')->searchable(),
            TextColumn::make('published_at')->label('Опубликован')->dateTime('d.m.Y H:i')->placeholder('Черновик'),
        ])->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBuilderTemplates::route('/'),
            'create' => CreateBuilderTemplate::route('/create'),
            'edit' => EditBuilderTemplate::route('/{record}/edit'),
        ];
    }
}
