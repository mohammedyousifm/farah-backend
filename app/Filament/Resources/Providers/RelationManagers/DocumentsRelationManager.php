<?php

namespace App\Filament\Resources\Providers\RelationManagers;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Filament\Concerns\ModuleRelationManager;
use App\Filament\Resources\ProviderDocuments\ProviderDocumentResource;
use App\Filament\Support\Act;
use App\Filament\Support\Fmt;
use App\Models\ProviderDocument;
use App\Models\ProviderDocumentRequest;
use App\Services\ProviderService;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class DocumentsRelationManager extends RelationManager
{
    use ModuleRelationManager;

    protected static string $relationship = 'documents';

    protected static string $permissionModule = 'documents';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.resources.documents.plural');
    }

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        $count = $ownerRecord->documents()->where('status', DocumentStatus::Pending)->count();

        return $count ? (string) $count : null;
    }

    public static function getBadgeColor(Model $ownerRecord, string $pageClass): ?string
    {
        return 'warning';
    }

    /** Admins may upload a document on behalf of the provider; it enters the queue as pending. */
    public function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Select::make('type')->label(__('admin.fields.document_type'))->options(DocumentType::class)->required(),
            Select::make('sector_id')->label(__('admin.fields.sector'))
                ->options(fn () => $this->getOwnerRecord()->sectors()->pluck('sectors.'.Fmt::col('name'), 'sectors.id'))->placeholder('-'),
            TextInput::make('title')->label(__('admin.fields.title'))->maxLength(150),
            TextInput::make('number')->label(__('admin.fields.document_number'))->maxLength(50),
            DatePicker::make('issued_at')->label(__('admin.fields.issued_at')),
            DatePicker::make('expires_at')->label(__('admin.fields.expires_at')),
            // Private disk, same folder as the provider's own uploads. Not image/*: that lets SVG (a script carrier) in.
            FileUpload::make('file_path')->label(__('admin.fields.file'))->required()->columnSpanFull()
                ->disk(ProviderDocument::DISK)->visibility('private')->directory(fn () => 'providers/'.$this->getOwnerRecord()->getKey().'/documents')
                ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png', 'image/webp'])->maxSize(10240),
        ]);
    }

    public function table(Table $table): Table
    {
        $service = fn () => app(ProviderService::class);

        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('sector')->orderByRaw("CASE WHEN status = 'pending' THEN 0 ELSE 1 END"))
            ->defaultSort('created_at', 'desc')
            // What the provider was asked for and has not sent yet.
            ->description(function () {
                $open = $this->getOwnerRecord()->documentRequests()->open()->get();

                return $open->isEmpty() ? null : __('admin.documents.waiting_for', ['list' => $open->map->label()->implode('، ')]);
            })
            ->columns([
                TextColumn::make('type')->label(__('admin.fields.document_type'))->badge()->color('gray'),
                TextColumn::make('number')->label(__('admin.fields.document_number'))->placeholder('-'),
                TextColumn::make('sector.name_ar')->formatStateUsing(Fmt::localized('sector.name'))->label(__('admin.fields.sector'))->placeholder('-'),
                TextColumn::make('status')->label(__('admin.fields.status'))->badge(),
                TextColumn::make('expires_at')->label(__('admin.fields.expires_at'))->date()->placeholder('-')
                    ->color(fn (ProviderDocument $r) => ProviderDocumentResource::expiryColor($r)),
                TextColumn::make('rejection_reason')->label(__('admin.fields.status_reason'))->placeholder('-')->limit(40)->toggleable(),
                TextColumn::make('created_at')->label(__('admin.fields.uploaded_at'))->since(),
            ])
            ->headerActions([
                Action::make('request')->label(__('admin.documents.request'))->icon('heroicon-o-document-plus')->color('warning')
                    ->visible(fn () => Act::can('documents.manage'))
                    ->modalDescription(__('admin.documents.request_hint'))
                    ->schema([
                        Select::make('type')->label(__('admin.fields.document_type'))->options(DocumentType::class)->required(),
                        Textarea::make('note')->label(__('admin.documents.request_note'))->rows(2)->maxLength(500)
                            ->placeholder(__('admin.documents.request_note_placeholder')),
                    ])
                    ->action(fn (array $data) => Act::run(fn () => $service()->requestDocument(
                        $this->getOwnerRecord(), DocumentType::from($data['type'] instanceof DocumentType ? $data['type']->value : (string) $data['type']), $data['note'] ?? null, auth()->user(),
                    ), __('admin.documents.requested'))),
                Action::make('cancelRequest')->label(__('admin.documents.cancel_request'))->icon('heroicon-o-x-mark')->color('gray')
                    ->visible(fn () => Act::can('documents.manage') && $this->getOwnerRecord()->documentRequests()->open()->exists())
                    ->schema([
                        Select::make('request_id')->label(__('admin.documents.open_requests'))->required()
                            ->options(fn () => $this->getOwnerRecord()->documentRequests()->open()->get()
                                ->mapWithKeys(fn (ProviderDocumentRequest $r) => [$r->id => $r->label()])),
                    ])
                    ->action(fn (array $data) => $this->getOwnerRecord()->documentRequests()->open()->whereKey($data['request_id'])->delete()),
                CreateAction::make()->mutateDataUsing(fn (array $data) => $data + ['status' => DocumentStatus::Pending])
                    ->after(fn (ProviderDocument $record) => $service()->documentUploaded($record)),
            ])
            ->recordActions([
                Action::make('file')->label(__('admin.actions.preview'))->icon('heroicon-o-eye')->color('gray')
                    ->url(fn (ProviderDocument $r) => $r->fileUrl(), true),
                Action::make('approve')->label(__('admin.actions.approve'))->icon('heroicon-o-check-circle')->color('success')
                    ->visible(fn (ProviderDocument $r) => $r->status !== DocumentStatus::Approved && Act::can('documents.manage'))
                    ->requiresConfirmation()
                    ->action(fn (ProviderDocument $record) => Act::run(fn () => $service()->approveDocument($record, auth()->user()))),
                Act::withReason('reject', __('admin.actions.reject'), fn (ProviderDocument $r, string $reason) => $service()->rejectDocument($r, auth()->user(), $reason))
                    ->visible(fn (ProviderDocument $r) => $r->status === DocumentStatus::Pending && Act::can('documents.manage')),
            ]);
    }
}
