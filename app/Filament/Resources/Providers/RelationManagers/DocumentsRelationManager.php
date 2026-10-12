<?php

namespace App\Filament\Resources\Providers\RelationManagers;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Filament\Concerns\ModuleRelationManager;
use App\Filament\Resources\ProviderDocuments\ProviderDocumentResource;
use App\Filament\Support\Act;
use App\Filament\Support\Fmt;
use App\Models\ProviderDocument;
use App\Services\ProviderService;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
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
                ->options(fn () => $this->getOwnerRecord()->sectors()->pluck('sectors.name_ar', 'sectors.id'))->placeholder('-'),
            TextInput::make('title')->label(__('admin.fields.title')),
            TextInput::make('number')->label(__('admin.fields.document_number')),
            DatePicker::make('issued_at')->label(__('admin.fields.issued_at')),
            DatePicker::make('expires_at')->label(__('admin.fields.expires_at')),
            FileUpload::make('file_path')->label(__('admin.fields.file'))->required()->disk(ProviderDocument::DISK)->visibility('private')->directory('documents')
                ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png', 'image/webp']), // not image/*: that lets SVG (a script carrier) in->maxSize(10240)->columnSpanFull(),
        ]);
    }

    public function table(Table $table): Table
    {
        $service = fn () => app(ProviderService::class);

        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('sector')->orderByRaw("CASE WHEN status = 'pending' THEN 0 ELSE 1 END"))
            ->defaultSort('created_at', 'desc')
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
                CreateAction::make()->mutateDataUsing(fn (array $data) => $data + ['status' => DocumentStatus::Pending]),
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
