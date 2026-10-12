<?php

namespace App\Filament\Resources\ProviderDocuments;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Filament\Concerns\ModuleResource;
use App\Filament\NavGroup;
use App\Filament\Resources\ProviderDocuments\Pages\ManageProviderDocuments;
use App\Filament\Resources\Providers\ProviderResource;
use App\Filament\Support\Act;
use App\Filament\Support\Fmt;
use App\Models\ProviderDocument;
use App\Models\Setting;
use App\Services\KpiService;
use App\Services\ProviderService;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/** Work-queue: provider documents waiting for review, plus expiry monitoring. */
class ProviderDocumentResource extends Resource
{
    use ModuleResource;

    protected static ?string $model = ProviderDocument::class;

    protected static string $permissionModule = 'documents';

    protected static string $langKey = 'documents';

    protected static string|\UnitEnum|null $navigationGroup = NavGroup::Approvals;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-document-check';

    protected static ?int $navigationSort = 20;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        $count = app(KpiService::class)->queue()['documents'];

        return $count ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function isImage(?string $path): bool
    {
        return $path && in_array(Str::lower(pathinfo($path, PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'png', 'gif', 'webp'], true);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->columns(3)->components([
            TextEntry::make('provider.name_ar')->formatStateUsing(Fmt::localized('provider.name'))->label(__('admin.fields.provider'))
                ->url(fn (ProviderDocument $r) => ProviderResource::getUrl('view', ['record' => $r->provider_id])),
            TextEntry::make('type')->label(__('admin.fields.document_type'))->badge(),
            TextEntry::make('status')->label(__('admin.fields.status'))->badge(),
            TextEntry::make('title')->label(__('admin.fields.title'))->placeholder('-'),
            TextEntry::make('number')->label(__('admin.fields.document_number'))->placeholder('-')->copyable(),
            TextEntry::make('sector.name_ar')->formatStateUsing(Fmt::localized('sector.name'))->label(__('admin.fields.sector'))->placeholder('-'),
            TextEntry::make('issued_at')->label(__('admin.fields.issued_at'))->date()->placeholder('-'),
            TextEntry::make('expires_at')->label(__('admin.fields.expires_at'))->date()->placeholder('-')
                ->color(fn (ProviderDocument $r) => static::expiryColor($r)),
            TextEntry::make('reviewer.name')->label(__('admin.fields.reviewed_by'))->placeholder('-')
                ->helperText(fn (ProviderDocument $r) => $r->reviewed_at?->toDateTimeString()),
            TextEntry::make('rejection_reason')->label(__('admin.fields.status_reason'))->placeholder('-')->color('danger')->columnSpanFull(),
            ImageEntry::make('file_path')->label(__('admin.fields.file'))->disk(ProviderDocument::DISK)->visibility('private')->imageHeight(420)->columnSpanFull()
                ->visible(fn (ProviderDocument $r) => static::isImage($r->file_path)),
            TextEntry::make('file_link')->label(__('admin.fields.file'))->columnSpanFull()
                ->state(fn () => __('admin.documents.open_file'))
                ->url(fn (ProviderDocument $r) => $r->fileUrl(), true)
                ->icon('heroicon-o-arrow-top-right-on-square')->color('primary'),
        ]);
    }

    public static function expiryColor(ProviderDocument $doc): ?string
    {
        if (! $doc->expires_at) {
            return null;
        }
        if ($doc->expires_at->isPast()) {
            return 'danger';
        }

        return $doc->isExpiringSoon((int) Setting::get('document_expiry_warning_days')) ? 'warning' : null;
    }

    public static function table(Table $table): Table
    {
        $service = fn () => app(ProviderService::class);

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['provider', 'sector', 'reviewer'])
                ->orderByRaw("CASE WHEN status = 'pending' THEN 0 ELSE 1 END"))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('provider.name_ar')->formatStateUsing(Fmt::localized('provider.name'))->label(__('admin.fields.provider'))->searchable()->weight('bold')
                    ->url(fn (ProviderDocument $r) => ProviderResource::getUrl('view', ['record' => $r->provider_id])),
                TextColumn::make('type')->label(__('admin.fields.document_type'))->badge()->color('gray'),
                TextColumn::make('number')->label(__('admin.fields.document_number'))->placeholder('-')->searchable()->toggleable(),
                TextColumn::make('sector.name_ar')->formatStateUsing(Fmt::localized('sector.name'))->label(__('admin.fields.sector'))->placeholder('-')->toggleable(),
                TextColumn::make('status')->label(__('admin.fields.status'))->badge(),
                TextColumn::make('expires_at')->label(__('admin.fields.expires_at'))->date()->sortable()->placeholder('-')
                    ->color(fn (ProviderDocument $r) => static::expiryColor($r))
                    ->description(fn (ProviderDocument $r) => $r->expires_at?->diffForHumans()),
                TextColumn::make('created_at')->label(__('admin.fields.uploaded_at'))->since()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('admin.fields.status'))->options(DocumentStatus::class),
                SelectFilter::make('type')->label(__('admin.fields.document_type'))->options(DocumentType::class),
                SelectFilter::make('provider_id')->label(__('admin.fields.provider'))->relationship('provider', 'name_ar')->getOptionLabelFromRecordUsing(fn ($record) => Fmt::tr($record, 'name'))->searchable()->preload(),
                Filter::make('expiring_soon')->label(__('admin.documents.expiring_soon'))
                    ->query(fn (Builder $q) => $q->whereBetween('expires_at', [
                        now()->toDateString(),
                        now()->addDays((int) Setting::get('document_expiry_warning_days'))->toDateString(),
                    ])),
                Filter::make('expired')->label(__('admin.documents.expired'))
                    ->query(fn (Builder $q) => $q->where('expires_at', '<', now()->toDateString())),
            ])
            ->recordActions([
                Action::make('file')->label(__('admin.actions.preview'))->icon('heroicon-o-eye')->color('gray')
                    ->url(fn (ProviderDocument $r) => $r->fileUrl(), true),
                ViewAction::make(),
                Action::make('approve')->label(__('admin.actions.approve'))->icon('heroicon-o-check-circle')->color('success')
                    ->visible(fn (ProviderDocument $r) => static::canManage() && $r->status !== DocumentStatus::Approved)
                    ->requiresConfirmation()
                    ->action(fn (ProviderDocument $record) => Act::run(fn () => $service()->approveDocument($record, auth()->user()))),
                Act::withReason('reject', __('admin.actions.reject'), fn (ProviderDocument $r, string $reason) => $service()->rejectDocument($r, auth()->user(), $reason))
                    ->visible(fn (ProviderDocument $r) => static::canManage() && $r->status === DocumentStatus::Pending),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageProviderDocuments::route('/')];
    }
}
