<?php

namespace App\Filament\Provider\Resources\Reviews;

use App\Filament\Provider\Concerns\ProviderResource;
use App\Filament\Provider\ProviderNavGroup;
use App\Filament\Provider\Resources\Reviews\Pages\ManageReviews;
use App\Filament\Provider\Support\ProviderAccess;
use App\Filament\Support\Act;
use App\Filament\Support\Fmt;
use App\Models\Review;
use App\Notifications\SystemNotification;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** Published customer reviews of the provider's venues, with a public reply. */
class ReviewResource extends Resource
{
    use ProviderResource;

    protected static ?string $model = Review::class;

    protected static string $area = 'reviews';

    protected static ?string $viewPermission = null;

    protected static ?string $managePermission = 'reviews.reply';

    protected static string $langKey = 'reviews';

    protected static ?string $slug = 'reviews';

    protected static string|\UnitEnum|null $navigationGroup = ProviderNavGroup::Bookings;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-star';

    protected static ?int $navigationSort = 5;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('status', 'published');
    }

    public static function getNavigationBadge(): ?string
    {
        $count = ProviderAccess::provider()?->reviews()->where('status', 'published')->whereNull('provider_reply')->count();

        return $count ? (string) $count : null;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['user', 'venue']))
            ->defaultSort('created_at', 'desc')
            ->emptyStateIcon('heroicon-o-star')
            ->emptyStateHeading(__('provider.reviews.empty'))
            ->emptyStateDescription(__('provider.reviews.empty_hint'))
            ->columns([
                TextColumn::make('rating')->label(__('provider.fields.rating'))
                    ->formatStateUsing(fn ($state) => str_repeat('★', (int) $state).str_repeat('☆', 5 - (int) $state))
                    ->color('warning')->sortable(),
                TextColumn::make('comment')->label(__('provider.fields.comment'))->wrap()->limit(160)->placeholder('-')
                    ->description(fn (Review $r) => $r->user?->name),
                TextColumn::make('venue.name_ar')->visible(fn () => ProviderAccess::manyStores())->formatStateUsing(Fmt::localized('venue.name'))->label(__('provider.fields.venue'))->limit(30),
                TextColumn::make('provider_reply')->label(__('provider.fields.provider_reply'))->wrap()->limit(120)
                    ->placeholder(__('provider.reviews.no_reply'))
                    ->description(fn (Review $r) => $r->replied_at?->diffForHumans()),
                TextColumn::make('created_at')->label(__('provider.fields.created_at'))->since()->sortable(),
            ])
            ->filters([
                SelectFilter::make('rating')->label(__('provider.fields.rating'))->options([5 => '5 ★', 4 => '4 ★', 3 => '3 ★', 2 => '2 ★', 1 => '1 ★']),
                SelectFilter::make('venue_id')->visible(fn () => ProviderAccess::manyStores())->label(__('provider.fields.venue'))
                    ->options(fn () => ProviderAccess::provider()?->venues()->orderBy('name_ar')->pluck('name_ar', 'id') ?? []),
                TernaryFilter::make('replied')->label(__('provider.reviews.replied'))
                    ->queries(
                        true: fn (Builder $q) => $q->whereNotNull('provider_reply'),
                        false: fn (Builder $q) => $q->whereNull('provider_reply'),
                    ),
            ])
            ->recordActions([
                Action::make('reply')->label(fn (Review $r) => $r->provider_reply ? __('provider.reviews.edit_reply') : __('provider.reviews.reply'))
                    ->icon('heroicon-o-chat-bubble-left-right')
                    ->visible(fn () => static::canManage())
                    ->fillForm(fn (Review $r) => ['reply' => $r->provider_reply])
                    ->schema([Textarea::make('reply')->label(__('provider.fields.provider_reply'))->required()->maxLength(2000)->rows(4)])
                    ->action(fn (Review $record, array $data) => Act::run(
                        function () use ($record, $data) {
                            $record->update(['provider_reply' => $data['reply'], 'replied_at' => now()]);
                            if ($record->user) {
                                SystemNotification::send($record->user, 'review_replied', ['venue' => $record->venue?->tr('name') ?? ''], ['review_id' => $record->id, 'venue_id' => $record->venue_id]);
                            }
                        },
                        __('api.reviews.replied'),
                    )),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageReviews::route('/')];
    }
}
