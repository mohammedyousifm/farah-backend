<?php

namespace App\Filament\Resources\Sectors\RelationManagers;

use App\Filament\Concerns\ModuleRelationManager;
use App\Filament\Support\Fmt;
use App\Filament\Support\Upload;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class CategoriesRelationManager extends RelationManager
{
    use ModuleRelationManager;

    protected static string $relationship = 'categories';

    protected static string $permissionModule = 'sectors';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.resources.categories.plural');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('name_ar')->label(__('admin.fields.name_ar'))->required(),
            TextInput::make('name_en')->label(__('admin.fields.name_en'))->required()
                ->live(onBlur: true)
                ->afterStateUpdated(fn ($state, callable $set, $get) => blank($get('slug')) ? $set('slug', Str::slug((string) $state)) : null),
            TextInput::make('slug')->label(__('admin.fields.slug'))->required()->unique('categories', 'slug', ignoreRecord: true),
            Select::make('parent_id')->label(__('admin.fields.parent'))
                ->options(fn () => $this->getOwnerRecord()->categories()->whereNull('parent_id')->pluck('name_ar', 'id'))
                ->placeholder('-'),
            TextInput::make('icon')->label(__('admin.fields.icon')),
            TextInput::make('sort_order')->label(__('admin.fields.sort_order'))->numeric()->default(0),
            FileUpload::make('image')->label(__('admin.fields.image'))->acceptedFileTypes(Upload::IMAGES)->maxSize(4096)->disk('public')->directory('categories'),
            Toggle::make('is_active')->label(__('admin.fields.is_active'))->default(true),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->columns([
                TextColumn::make('name_ar')->label(__('admin.fields.name_ar'))->searchable(),
                TextColumn::make('name_en')->label(__('admin.fields.name_en')),
                TextColumn::make('parent.name_ar')->formatStateUsing(Fmt::localized('parent.name'))->label(__('admin.fields.parent'))->placeholder('-'),
                TextColumn::make('activities_count')->label(__('admin.fields.activities'))->counts('activities')->badge(),
                ToggleColumn::make('is_active')->label(__('admin.fields.is_active')),
            ])
            ->headerActions([CreateAction::make()])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }
}
