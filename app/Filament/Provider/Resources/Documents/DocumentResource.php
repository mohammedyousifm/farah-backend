<?php

namespace App\Filament\Provider\Resources\Documents;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Exceptions\BusinessException;
use App\Filament\Provider\Concerns\ProviderResource;
use App\Filament\Provider\ProviderNavGroup;
use App\Filament\Provider\Resources\Documents\Pages\ManageDocuments;
use App\Filament\Provider\Support\ProviderAccess;
use App\Filament\Support\Act;
use App\Filament\Support\Fmt;
use App\Models\ProviderDocument;
use App\Services\ProviderService;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/** Verification documents (same rules as the provider API DocumentController). */
class DocumentResource extends Resource
{
    use ProviderResource;

    protected static ?string $model = ProviderDocument::class;

    protected static ?string $tenantRelationshipName = 'documents';

    protected static string $area = 'documents';

    protected static ?string $viewPermission = 'profile.manage';

    protected static ?string $managePermission = 'profile.manage';

    protected static string $langKey = 'documents';

    protected static ?string $slug = 'documents';

    protected static string|\UnitEnum|null $navigationGroup = ProviderNavGroup::Account;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-document-check';

    protected static ?int $navigationSort = 2;

    public static function getNavigationBadge(): ?string
    {
        $provider = ProviderAccess::provider();
        // Rejected or expired documents, plus what the platform asked for and has not received.
        $count = $provider ? $provider->documents()->whereIn('status', [DocumentStatus::Rejected, DocumentStatus::Expired])->count()
            + $provider->documentRequests()->open()->count() : 0;

        return $count ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function form(Schema $schema): Schema
    {
        $provider = ProviderAccess::provider();

        return $schema->columns(2)->components([
            Select::make('type')->label(__('provider.fields.document_type'))->options(DocumentType::class)->required(),
            TextInput::make('title')->label(__('provider.fields.title'))->maxLength(150),
            TextInput::make('number')->label(__('provider.fields.document_number'))->maxLength(50),
            Select::make('sector_id')->label(__('provider.fields.sector'))
                ->options(fn () => $provider?->sectors()->pluck('sectors.'.Fmt::col('name'), 'sectors.id') ?? [])
                ->helperText(__('provider.documents.sector_hint')),
            DatePicker::make('issued_at')->label(__('provider.fields.issued_at'))->maxDate(now()),
            DatePicker::make('expires_at')->label(__('provider.fields.expires_at'))->minDate(now()->addDay()),
            FileUpload::make('file_path')->label(__('provider.fields.file'))->required()->columnSpanFull()
                ->disk(ProviderDocument::DISK)->visibility('private')->directory(fn () => 'providers/'.$provider?->id.'/documents')
                ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png'])->maxSize(10240)
                ->helperText(__('provider.documents.file_hint')),
        ]);
    }

    /** Create a document, or replace $old (rejected / expired / pending / approved-but-expiring). */
    public static function store(array $data, ?ProviderDocument $old = null): ProviderDocument
    {
        $provider = ProviderAccess::provider();

        if ($old && $old->status === DocumentStatus::Approved && ! ($old->expires_at && $old->isExpiringSoon())) {
            Storage::disk(ProviderDocument::DISK)->delete($data['file_path']);
            throw new BusinessException(__('api.providers.document_locked'), 'document_locked');
        }

        $document = DB::transaction(function () use ($provider, $data, $old) {
            if ($old) {
                if ($old->file_path && ! str_starts_with($old->file_path, 'demo/')) {
                    Storage::disk(ProviderDocument::DISK)->delete($old->file_path);
                }
                $old->delete();
            }

            return $provider->documents()->create([
                'type' => $data['type'],
                'title' => $data['title'] ?? null,
                'number' => $data['number'] ?? null,
                'sector_id' => $data['sector_id'] ?? null,
                'issued_at' => $data['issued_at'] ?? null,
                'expires_at' => $data['expires_at'] ?? null,
                'file_path' => $data['file_path'],
                'status' => DocumentStatus::Pending,
            ]);
        });
        app(ProviderService::class)->documentUploaded($document);

        return $document;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('sector'))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('type')->label(__('provider.fields.document_type'))->badge()->color('gray')
                    ->description(fn (ProviderDocument $r) => $r->title),
                TextColumn::make('number')->label(__('provider.fields.document_number'))->placeholder('-'),
                TextColumn::make('sector.'.Fmt::col('name'))->label(__('provider.fields.sector'))->placeholder('-'),
                TextColumn::make('issued_at')->label(__('provider.fields.issued_at'))->date('Y-m-d')->placeholder('-'),
                TextColumn::make('expires_at')->label(__('provider.fields.expires_at'))->date('Y-m-d')->placeholder('-')
                    ->color(fn (ProviderDocument $r) => $r->isExpiringSoon() ? 'warning' : null),
                TextColumn::make('status')->label(__('provider.fields.status'))->badge()
                    ->description(fn (ProviderDocument $r) => $r->status === DocumentStatus::Rejected ? $r->rejection_reason : null),
                TextColumn::make('created_at')->label(__('provider.fields.uploaded_at'))->since(),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('provider.fields.status'))->options(DocumentStatus::class),
            ])
            ->recordActions([
                Action::make('open')->label(__('provider.actions.open_file'))->icon('heroicon-o-arrow-top-right-on-square')->color('gray')
                    ->url(fn (ProviderDocument $r) => $r->fileUrl(), true),
                Action::make('replace')->label(__('provider.actions.reupload'))->icon('heroicon-o-arrow-up-tray')->color('warning')
                    ->visible(fn (ProviderDocument $r) => static::canManage()
                        && ($r->status !== DocumentStatus::Approved || ($r->expires_at && $r->isExpiringSoon())))
                    ->fillForm(fn (ProviderDocument $r) => [
                        'type' => $r->type, 'title' => $r->title, 'number' => $r->number, 'sector_id' => $r->sector_id,
                        'issued_at' => $r->issued_at, 'expires_at' => $r->expires_at,
                    ])
                    ->schema(fn (Schema $schema) => static::form($schema))
                    ->action(fn (ProviderDocument $record, array $data) => Act::run(fn () => static::store($data, $record), __('provider.documents.uploaded'))),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageDocuments::route('/')];
    }
}
