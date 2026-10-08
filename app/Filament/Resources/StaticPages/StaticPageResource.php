<?php

namespace App\Filament\Resources\StaticPages;

use App\Filament\Concerns\ModuleResource;
use App\Filament\NavGroup;
use App\Filament\Resources\StaticPages\Pages\CreateStaticPage;
use App\Filament\Resources\StaticPages\Pages\EditStaticPage;
use App\Filament\Resources\StaticPages\Pages\ListStaticPages;
use App\Filament\Support\Fmt;
use App\Models\Page;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;

/** Static content pages served to the app (terms, privacy, about, ...). */
class StaticPageResource extends Resource
{
    use ModuleResource;

    protected static ?string $model = Page::class;

    protected static string $permissionModule = 'content';

    protected static string $langKey = 'pages';

    protected static ?string $slug = 'static-pages';

    protected static string|\UnitEnum|null $navigationGroup = NavGroup::Content;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-document-text';

    protected static ?int $navigationSort = 20;

    protected static ?string $recordTitleAttribute = 'title_ar';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(3)->columnSpanFull()->schema([
                TextInput::make('slug')->label(__('admin.fields.slug'))->required()->maxLength(255)
                    ->unique(ignoreRecord: true)->alphaDash()->helperText(__('admin.hints.page_slug')),
                TextInput::make('title_ar')->label(__('admin.fields.title_ar'))->required()->maxLength(255),
                TextInput::make('title_en')->label(__('admin.fields.title_en'))->required()->maxLength(255),
                Toggle::make('is_published')->label(__('admin.fields.is_published'))->default(true),
            ]),
            Tabs::make()->columnSpanFull()->tabs([
                Tab::make(__('admin.tabs.arabic'))->schema([
                    RichEditor::make('body_ar')->label(__('admin.fields.body_ar'))->extraInputAttributes(['dir' => 'rtl']),
                ]),
                Tab::make(__('admin.tabs.english'))->schema([
                    RichEditor::make('body_en')->label(__('admin.fields.body_en'))->extraInputAttributes(['dir' => 'ltr']),
                ]),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('slug')
            ->columns([
                TextColumn::make(Fmt::col('title'))->label(__('admin.fields.title'))->searchable(['title_ar', 'title_en'])->weight('bold'),
                TextColumn::make('slug')->label(__('admin.fields.slug'))->fontFamily('mono')->copyable(),
                ToggleColumn::make('is_published')->label(__('admin.fields.is_published'))->disabled(fn () => ! static::canManage()),
                TextColumn::make('updated_at')->label(__('admin.fields.updated_at'))->since()->sortable(),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStaticPages::route('/'),
            'create' => CreateStaticPage::route('/create'),
            'edit' => EditStaticPage::route('/{record}/edit'),
        ];
    }
}
