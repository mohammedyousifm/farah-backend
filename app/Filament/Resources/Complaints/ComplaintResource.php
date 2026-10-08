<?php

namespace App\Filament\Resources\Complaints;

use App\Enums\ComplaintStatus;
use App\Enums\UserType;
use App\Filament\Concerns\ModuleResource;
use App\Filament\NavGroup;
use App\Filament\Resources\Complaints\Pages\ListComplaints;
use App\Filament\Resources\Complaints\Pages\ViewComplaint;
use App\Filament\Resources\Complaints\RelationManagers\RepliesRelationManager;
use App\Filament\Support\Fmt;
use App\Models\Complaint;
use App\Models\User;
use App\Services\KpiService;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ComplaintResource extends Resource
{
    use ModuleResource;

    protected static ?string $model = Complaint::class;

    protected static string $permissionModule = 'complaints';

    protected static string $langKey = 'complaints';

    protected static string|\UnitEnum|null $navigationGroup = NavGroup::Support;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-chat-bubble-left-right';

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'number';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        $count = app(KpiService::class)->queue()['complaints'];

        return $count ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    /** @return array<string, string> */
    public static function priorityOptions(): array
    {
        return collect(Complaint::PRIORITIES)->mapWithKeys(fn ($p) => [$p => __("admin.priorities.{$p}")])->all();
    }

    /** @return array<string, string> */
    public static function typeOptions(): array
    {
        return collect(Complaint::TYPES)->mapWithKeys(fn ($t) => [$t => __("admin.complaint_types.{$t}")])->all();
    }

    public static function priorityColor(?string $priority): string
    {
        return match ($priority) {
            'urgent' => 'danger',
            'high' => 'warning',
            'low' => 'gray',
            default => 'info',
        };
    }

    /** @return array<int, string> */
    public static function adminOptions(): array
    {
        return User::where('type', UserType::Admin)->where('status', 'active')->orderBy('name')->pluck('name', 'id')->all();
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columnSpanFull()->columns(4)->schema([
                TextEntry::make('number')->label(__('admin.fields.number'))->weight('bold')->copyable(),
                TextEntry::make('status')->label(__('admin.fields.status'))->badge(),
                TextEntry::make('priority')->label(__('admin.fields.priority'))->badge()
                    ->formatStateUsing(fn ($state) => __("admin.priorities.{$state}"))
                    ->color(fn ($state) => static::priorityColor($state)),
                TextEntry::make('type')->label(__('admin.fields.type'))->formatStateUsing(fn ($state) => __("admin.complaint_types.{$state}")),
                TextEntry::make('user.name')->label(__('admin.fields.customer'))->placeholder('-')
                    ->helperText(fn (Complaint $r) => Fmt::phone($r->user?->phone) ?? $r->user?->email),
                TextEntry::make('provider.name_ar')->formatStateUsing(Fmt::localized('provider.name'))->label(__('admin.fields.provider'))->placeholder('-'),
                TextEntry::make('order.number')->label(__('admin.fields.order'))->placeholder('-'),
                TextEntry::make('venue.name_ar')->formatStateUsing(Fmt::localized('venue.name'))->label(__('admin.fields.venue'))->placeholder('-'),
                TextEntry::make('assignee.name')->label(__('admin.fields.assigned_to'))->placeholder(__('admin.complaints.unassigned')),
                TextEntry::make('created_at')->label(__('admin.fields.created_at'))->dateTime(),
                TextEntry::make('resolved_at')->label(__('admin.fields.resolved_at'))->dateTime()->placeholder('-'),
            ]),
            Section::make(fn (Complaint $r) => $r->subject)->columnSpanFull()->schema([
                TextEntry::make('body')->label(__('admin.fields.message'))->hiddenLabel()->columnSpanFull(),
                TextEntry::make('resolution')->label(__('admin.fields.resolution'))->placeholder('-')->color('success')->columnSpanFull(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['user', 'provider', 'assignee'])->withCount('replies'))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('number')->label(__('admin.fields.number'))->searchable()->weight('bold'),
                TextColumn::make('subject')->label(__('admin.fields.subject'))->searchable()->limit(40)
                    ->description(fn (Complaint $r) => __("admin.complaint_types.{$r->type}")),
                TextColumn::make('user.name')->label(__('admin.fields.customer'))->placeholder('-')->searchable(),
                TextColumn::make('provider.name_ar')->formatStateUsing(Fmt::localized('provider.name'))->label(__('admin.fields.provider'))->placeholder('-')->limit(25)->toggleable(),
                TextColumn::make('priority')->label(__('admin.fields.priority'))->badge()
                    ->formatStateUsing(fn ($state) => __("admin.priorities.{$state}"))
                    ->color(fn ($state) => static::priorityColor($state)),
                TextColumn::make('status')->label(__('admin.fields.status'))->badge(),
                TextColumn::make('assignee.name')->label(__('admin.fields.assigned_to'))->placeholder('-'),
                TextColumn::make('replies_count')->label(__('admin.fields.replies'))->badge()->color('gray'),
                TextColumn::make('created_at')->label(__('admin.fields.created_at'))->since()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('admin.fields.status'))->options(ComplaintStatus::class)->multiple(),
                SelectFilter::make('priority')->label(__('admin.fields.priority'))->options(static::priorityOptions()),
                SelectFilter::make('type')->label(__('admin.fields.type'))->options(static::typeOptions()),
                SelectFilter::make('assigned_to')->label(__('admin.fields.assigned_to'))->options(fn () => static::adminOptions()),
                TernaryFilter::make('mine')->label(__('admin.complaints.assigned_to_me'))
                    ->queries(
                        true: fn (Builder $q) => $q->where('assigned_to', auth()->id()),
                        false: fn (Builder $q) => $q->whereNull('assigned_to'),
                    )
                    ->falseLabel(__('admin.complaints.unassigned')),
            ])
            ->recordActions([ViewAction::make()]);
    }

    public static function getRelations(): array
    {
        return [RepliesRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListComplaints::route('/'),
            'view' => ViewComplaint::route('/{record}'),
        ];
    }
}
