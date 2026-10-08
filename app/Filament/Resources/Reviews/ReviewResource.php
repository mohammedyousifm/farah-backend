<?php

namespace App\Filament\Resources\Reviews;

use App\Filament\Concerns\ModuleResource;
use App\Filament\NavGroup;
use App\Filament\Resources\Reviews\Pages\ManageReviews;
use App\Filament\Support\Act;
use App\Filament\Support\Fmt;
use App\Models\Review;
use App\Services\ReviewModerationService;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/** Review moderation: hide / publish (Review::booted keeps rating aggregates in sync). */
class ReviewResource extends Resource
{
    use ModuleResource;

    protected static ?string $model = Review::class;

    protected static string $permissionModule = 'reviews';

    protected static string $langKey = 'reviews';

    protected static string|\UnitEnum|null $navigationGroup = NavGroup::Support;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-star';

    protected static ?int $navigationSort = 20;

    public static function canCreate(): bool
    {
        return false;
    }

    /** @return array<string, string> */
    public static function statusOptions(): array
    {
        return ['published' => __('admin.review_status.published'), 'hidden' => __('admin.review_status.hidden')];
    }

    public static function stars(int|string|null $rating): string
    {
        $rating = (int) $rating;

        return str_repeat('★', $rating).str_repeat('☆', max(0, 5 - $rating));
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextEntry::make('rating')->label(__('admin.fields.rating'))->formatStateUsing(fn ($state) => static::stars($state))->color('warning'),
            TextEntry::make('status')->label(__('admin.fields.status'))->badge()
                ->formatStateUsing(fn ($state) => static::statusOptions()[$state] ?? $state)
                ->color(fn ($state) => $state === 'hidden' ? 'danger' : 'success'),
            TextEntry::make('user.name')->label(__('admin.fields.customer'))->placeholder('-'),
            TextEntry::make('order.number')->label(__('admin.fields.order'))->placeholder('-'),
            TextEntry::make('venue.name_ar')->formatStateUsing(Fmt::localized('venue.name'))->label(__('admin.fields.venue')),
            TextEntry::make('provider.name_ar')->formatStateUsing(Fmt::localized('provider.name'))->label(__('admin.fields.provider')),
            TextEntry::make('comment')->label(__('admin.fields.comment'))->placeholder('-')->columnSpanFull(),
            TextEntry::make('provider_reply')->label(__('admin.fields.provider_reply'))->placeholder('-')->columnSpanFull()
                ->helperText(fn (Review $r) => $r->replied_at?->toDateTimeString()),
            TextEntry::make('created_at')->label(__('admin.fields.created_at'))->dateTime(),
        ]);
    }

    public static function table(Table $table): Table
    {
        $moderation = fn () => app(ReviewModerationService::class);

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['user', 'venue', 'provider', 'order']))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('rating')->label(__('admin.fields.rating'))->formatStateUsing(fn ($state) => static::stars($state))
                    ->color(fn ($state) => $state <= 2 ? 'danger' : 'warning')->sortable(),
                TextColumn::make('comment')->label(__('admin.fields.comment'))->limit(60)->wrap()->placeholder('-')->searchable(),
                TextColumn::make('venue.name_ar')->formatStateUsing(Fmt::localized('venue.name'))->label(__('admin.fields.venue'))->limit(30)->searchable(),
                TextColumn::make('provider.name_ar')->formatStateUsing(Fmt::localized('provider.name'))->label(__('admin.fields.provider'))->limit(25)->toggleable(),
                TextColumn::make('user.name')->label(__('admin.fields.customer'))->placeholder('-')->searchable(),
                TextColumn::make('status')->label(__('admin.fields.status'))->badge()
                    ->formatStateUsing(fn ($state) => static::statusOptions()[$state] ?? $state)
                    ->color(fn ($state) => $state === 'hidden' ? 'danger' : 'success'),
                TextColumn::make('provider_reply')->label(__('admin.fields.provider_reply'))->limit(40)->placeholder('-')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')->label(__('admin.fields.created_at'))->since()->sortable(),
            ])
            ->filters([
                SelectFilter::make('rating')->label(__('admin.fields.rating'))
                    ->options(collect(range(5, 1))->mapWithKeys(fn ($r) => [$r => static::stars($r)])->all()),
                SelectFilter::make('status')->label(__('admin.fields.status'))->options(static::statusOptions()),
                SelectFilter::make('provider_id')->label(__('admin.fields.provider'))->relationship('provider', 'name_ar')->getOptionLabelFromRecordUsing(fn ($record) => Fmt::tr($record, 'name'))->searchable(),
                SelectFilter::make('venue_id')->label(__('admin.fields.venue'))->relationship('venue', 'name_ar')->getOptionLabelFromRecordUsing(fn ($record) => Fmt::tr($record, 'name'))->searchable(),
            ])
            ->recordActions([
                ViewAction::make(),
                Action::make('hide')->label(__('admin.actions.hide'))->icon('heroicon-o-eye-slash')->color('danger')
                    ->visible(fn (Review $r) => static::canManage() && $r->status !== 'hidden')
                    ->requiresConfirmation()
                    ->schema([Textarea::make('reason')->label(__('admin.fields.reason'))->maxLength(1000)])
                    ->action(fn (Review $record, array $data) => Act::run(fn () => $moderation()->hide($record, auth()->user(), $data['reason'] ?? null))),
                Action::make('publish')->label(__('admin.actions.publish'))->icon('heroicon-o-eye')->color('success')
                    ->visible(fn (Review $r) => static::canManage() && $r->status === 'hidden')
                    ->requiresConfirmation()
                    ->action(fn (Review $record) => Act::run(fn () => $moderation()->publish($record, auth()->user()))),
                Act::withReason('remove_reply', __('admin.actions.remove_reply'), fn (Review $record, string $reason) => $moderation()->removeReply($record, auth()->user(), $reason),
                    'warning', 'heroicon-o-chat-bubble-left-ellipsis')
                    ->visible(fn (Review $r) => static::canManage() && filled($r->provider_reply)),
            ])
            ->toolbarActions([
                BulkAction::make('hide_selected')->label(__('admin.actions.hide'))->icon('heroicon-o-eye-slash')->color('danger')
                    ->visible(fn () => static::canManage())
                    ->requiresConfirmation()
                    ->schema([Textarea::make('reason')->label(__('admin.fields.reason'))->maxLength(1000)])
                    ->action(fn (Collection $records, array $data) => Act::run(fn () => $moderation()->hideMany($records, auth()->user(), $data['reason'] ?? null)))
                    ->deselectRecordsAfterCompletion(),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageReviews::route('/')];
    }
}
