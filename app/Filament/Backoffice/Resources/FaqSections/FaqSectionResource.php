<?php

namespace App\Filament\Backoffice\Resources\FaqSections;

use App\Filament\Backoffice\Resources\FaqSections\Pages\ManageFaqSections;
use App\Modules\Content\Domain\Models\FaqSection;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

final class FaqSectionResource extends Resource
{
    protected static ?string $model = FaqSection::class;

    protected static string|\UnitEnum|null $navigationGroup = 'Контент';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-list-bullet';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Разделы FAQ';

    protected static ?string $modelLabel = 'раздел FAQ';

    protected static ?string $pluralModelLabel = 'Разделы FAQ';

    public static function canViewAny(): bool
    {
        return Gate::allows('manage-content');
    }

    public static function canCreate(): bool
    {
        return static::canViewAny();
    }

    public static function canEdit(Model $record): bool
    {
        return static::canViewAny();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label('Название')->required()->maxLength(120),
            TextInput::make('code')->label('Код раздела')->required()->maxLength(50)
                ->regex('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/')
                ->unique(ignoreRecord: true)
                ->disabledOn('edit'),
            TextInput::make('sort_order')->label('Порядок')->required()
                ->integer()->minValue(0)->maxValue(999999)->default(100),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->columns([
                TextColumn::make('name')->label('Название')->searchable(),
                TextColumn::make('code')->label('Код раздела')->searchable()->copyable(),
                TextColumn::make('sort_order')->label('Порядок')->sortable(),
            ])
            ->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageFaqSections::route('/')];
    }
}
