<?php

namespace App\Filament\Provider\Resources\Venues\RelationManagers;

use App\Filament\Provider\Concerns\ProviderRelationManager;
use App\Filament\Provider\Resources\Venues\VenueResource;
use App\Models\VenueMedia;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/** Venue gallery: image / GIF / video uploads or an external video URL, sortable. */
class MediaRelationManager extends RelationManager
{
    use ProviderRelationManager;

    protected static string $relationship = 'media';

    protected static string $area = 'venues';

    protected static ?string $managePermission = 'venues.manage';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('provider.venues.media');
    }

    protected function canModifyOwner(): bool
    {
        return ! in_array($this->getOwnerRecord()->status, VenueResource::LOCKED, true);
    }

    public function form(Schema $schema): Schema
    {
        $venue = $this->getOwnerRecord();

        return $schema->components([
            Radio::make('source')->label(__('provider.fields.media_source'))->inline()->live()->default('file')
                ->options(['file' => __('provider.venues.media_upload'), 'url' => __('provider.venues.media_url')]),
            FileUpload::make('path')->label(__('provider.fields.file'))
                ->disk('public')->directory("venues/{$venue->provider_id}/{$venue->id}")
                ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'video/mp4', 'video/quicktime'])
                ->maxSize(51200)
                ->visible(fn (Get $get) => $get('source') !== 'url')
                ->required(fn (Get $get) => $get('source') !== 'url'),
            TextInput::make('url')->label(__('provider.fields.url'))->url()->rule('url:http,https')->maxLength(500)->placeholder('https://youtube.com/...')
                ->visible(fn (Get $get) => $get('source') === 'url')
                ->required(fn (Get $get) => $get('source') === 'url'),
        ]);
    }

    /** Same type detection as the provider API. */
    public static function attributes(array $data, int $sort): array
    {
        if (($data['source'] ?? 'file') !== 'url' && ! empty($data['path'])) {
            $mime = (string) Storage::disk('public')->mimeType($data['path']);
            $type = str_starts_with($mime, 'video/') ? 'video' : ($mime === 'image/gif' ? 'gif' : 'image');

            return ['type' => $type, 'path' => $data['path'], 'url' => null, 'sort_order' => $sort];
        }

        $url = (string) ($data['url'] ?? '');

        return [
            'type' => preg_match('/\.(jpe?g|png|webp)(\?.*)?$/i', $url) ? 'image' : (preg_match('/\.gif(\?.*)?$/i', $url) ? 'gif' : 'video'),
            'path' => null,
            'url' => $url,
            'sort_order' => $sort,
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->reorderable('sort_order', fn () => $this->canWrite())
            ->defaultSort('sort_order')
            ->columns([
                ImageColumn::make('preview')->label('')->imageHeight(56)
                    ->state(fn (VenueMedia $r) => in_array($r->type, ['image', 'gif'], true) ? $r->src() : null),
                TextColumn::make('type')->label(__('provider.fields.media_type'))->badge()
                    ->formatStateUsing(fn ($state) => __("provider.venues.media_types.{$state}")),
                TextColumn::make('link')->label(__('provider.fields.url'))->limit(50)
                    ->state(fn (VenueMedia $r) => $r->src())
                    ->url(fn (VenueMedia $r) => $r->src(), true),
            ])
            ->headerActions([
                CreateAction::make()->label(__('provider.venues.add_media'))
                    ->using(function (array $data) {
                        $venue = $this->getOwnerRecord();

                        return $venue->media()->create(static::attributes($data, (int) $venue->media()->max('sort_order') + 1));
                    }),
            ])
            ->recordActions([
                DeleteAction::make()->after(function (VenueMedia $record) {
                    if ($record->path && ! str_starts_with($record->path, 'demo/')) {
                        Storage::disk('public')->delete($record->path);
                    }
                }),
            ]);
    }
}
