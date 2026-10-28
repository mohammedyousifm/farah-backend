<?php

namespace App\Filament\Resources\HomeSections\RelationManagers;

use App\Filament\Concerns\ModuleRelationManager;
use App\Filament\Support\Fmt;
use App\Models\Category;
use App\Models\HomeSectionItem;
use App\Models\Provider;
use App\Models\Sector;
use App\Models\Venue;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** Manual items of a home section: banners (image / video / GIF) and links. */
class ItemsRelationManager extends RelationManager
{
    use ModuleRelationManager;

    protected static string $relationship = 'items';

    protected static string $permissionModule = 'content';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.fields.items');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('title_ar')->label(__('admin.fields.title_ar'))->maxLength(255),
            TextInput::make('title_en')->label(__('admin.fields.title_en'))->maxLength(255),
            Select::make('media_type')->label(__('admin.fields.media_type'))->live()->placeholder('-')
                ->options(collect(['image', 'video', 'gif'])->mapWithKeys(fn ($t) => [$t => __("admin.media_types.{$t}")])),
            TextInput::make('media_url')->label(__('admin.fields.media_url'))->url()->rule('url:http,https')->maxLength(255)
                ->helperText(__('admin.hints.media_url')),
            FileUpload::make('media_path')->label(__('admin.fields.media_file'))->columnSpanFull()
                ->disk('public')->directory('home')->maxSize(20480)
                ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'video/mp4', 'video/webm'])
                ->visible(fn (Get $get) => filled($get('media_type'))),
            Select::make('link_type')->label(__('admin.fields.link_type'))->required()->default('none')->live()
                ->options(static::linkTypes())
                ->afterStateUpdated(fn (Set $set) => $set('link_value', null)),
            Select::make('link_value')->label(__('admin.fields.link_target'))->searchable()->required()
                ->visible(fn (Get $get) => in_array($get('link_type'), ['venue', 'category', 'sector', 'provider'], true))
                ->options(fn (Get $get) => static::linkOptions((string) $get('link_type'))),
            TextInput::make('link_value')->label(__('admin.fields.url'))->url()->rule('url:http,https')->required()->maxLength(255)
                ->visible(fn (Get $get) => $get('link_type') === 'url'),
        ]);
    }

    /** @return array<string, string> */
    public static function linkTypes(): array
    {
        return collect(HomeSectionItem::LINK_TYPES)->mapWithKeys(fn ($t) => [$t => __("admin.link_types.{$t}")])->all();
    }

    /** @return array<int|string, string> */
    public static function linkOptions(string $type): array
    {
        return match ($type) {
            'venue' => Venue::whereNotIn('status', ['draft', 'rejected'])->latest('published_at')->limit(300)->get()->mapWithKeys(fn (Venue $v) => [$v->id => $v->tr('name')])->all(),
            'category' => Category::orderBy('sort_order')->pluck(Fmt::col('name'), 'id')->all(),
            'sector' => Sector::orderBy('sort_order')->pluck(Fmt::col('name'), 'id')->all(),
            'provider' => Provider::where('status', 'approved')->orderBy('name_ar')->pluck('name_ar', 'id')->all(),
            default => [],
        };
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->columns([
                ImageColumn::make('preview')->label('')->imageSize(48)
                    ->state(fn (HomeSectionItem $r) => in_array($r->media_type, ['image', 'gif'], true) ? $r->mediaSrc() : null),
                TextColumn::make(Fmt::col('title'))->label(__('admin.fields.title'))->placeholder('-')->weight('bold'),
                TextColumn::make('media_type')->label(__('admin.fields.media_type'))->badge()->placeholder('-')
                    ->formatStateUsing(fn ($state) => __("admin.media_types.{$state}")),
                TextColumn::make('link_type')->label(__('admin.fields.link_type'))
                    ->formatStateUsing(fn ($state) => __("admin.link_types.{$state}")),
                TextColumn::make('link_value')->label(__('admin.fields.link_target'))->placeholder('-')->limit(40),
            ])
            ->headerActions([
                CreateAction::make()->mutateDataUsing(fn (array $data) => $data + [
                    'sort_order' => (int) $this->getOwnerRecord()->items()->max('sort_order') + 1,
                ]),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }
}
