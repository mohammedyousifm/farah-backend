<?php

namespace App\Filament\Resources\Venues\RelationManagers;

use App\Filament\Concerns\ModuleRelationManager;
use App\Filament\Support\Fmt;
use App\Models\Activity;
use App\Services\VenueService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** Activities and their resources (tables, lanes, ...) are managed by the provider; admins see them read-only. */
class ActivitiesRelationManager extends RelationManager
{
    use ModuleRelationManager;

    protected static string $relationship = 'activities';

    protected static string $permissionModule = 'venues';

    public function isReadOnly(): bool
    {
        return true;
    }

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.fields.activities');
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['sector', 'category', 'resources']))
            ->paginated(false)
            ->columns([
                TextColumn::make('name_ar')->formatStateUsing(Fmt::localized('name'))->label(__('admin.fields.name'))->weight('bold')
                    ->description(fn (Activity $r) => $r->name_en),
                TextColumn::make('sector.name_ar')->formatStateUsing(Fmt::localized('sector.name'))->label(__('admin.fields.sector'))
                    ->description(fn (Activity $r) => $r->category?->tr('name')),
                TextColumn::make('price_per_hour')->label(__('admin.fields.price_per_hour'))->formatStateUsing(fn ($state) => Fmt::sar($state)),
                TextColumn::make('slot_minutes')->label(__('admin.fields.slot_minutes'))
                    ->formatStateUsing(fn ($state) => __('admin.venues.minutes', ['count' => $state]))
                    ->description(fn (Activity $r) => __('admin.venues.slot_price', ['price' => Fmt::sar($r->slotPrice())])),
                TextColumn::make('slots_range')->label(__('admin.fields.slots_range'))
                    ->state(fn (Activity $r) => $r->min_slots.' – '.$r->max_slots),
                TextColumn::make('capacity')->label(__('admin.fields.capacity'))->placeholder('-'),
                TextColumn::make('resources_list')->label(__('admin.fields.resources'))->badge()->color('info')->placeholder('-')
                    ->state(fn (Activity $r) => $r->resources
                        ->map(fn ($res) => $res->tr('name').($res->is_active ? '' : ' ('.__('admin.venues.inactive').')'))->all()),
                TextColumn::make('review_status')->label(__('admin.fields.review_status'))->badge()
                    ->formatStateUsing(fn ($state) => __('admin.venues.review.'.($state ?: Activity::APPROVED)))
                    ->color(fn ($state) => match ($state) {
                        Activity::PENDING => 'warning', Activity::REJECTED => 'danger', default => 'success'
                    })
                    ->description(fn (Activity $r) => $r->review_status === Activity::REJECTED ? $r->review_note : null),
                IconColumn::make('is_active')->label(__('admin.fields.is_active'))->boolean(),
            ])
            // An activity added to a live store waits here for a decision.
            ->recordActions([
                Action::make('approve_activity')->label(__('admin.venues.approve_activity'))->icon('heroicon-o-check-circle')->color('success')
                    ->visible(fn (Activity $r) => $r->review_status === Activity::PENDING && auth()->user()?->can('venues.manage'))
                    ->requiresConfirmation()
                    ->action(function (Activity $r) {
                        app(VenueService::class)->approveActivity($r, auth()->user());
                        Notification::make()->success()->title(__('admin.venues.activity_approved'))->send();
                    }),
                Action::make('reject_activity')->label(__('admin.venues.reject_activity'))->icon('heroicon-o-x-circle')->color('danger')
                    ->visible(fn (Activity $r) => $r->review_status === Activity::PENDING && auth()->user()?->can('venues.manage'))
                    ->schema([Textarea::make('reason')->label(__('admin.fields.reason'))->required()->maxLength(500)])
                    ->action(function (Activity $r, array $data) {
                        app(VenueService::class)->rejectActivity($r, auth()->user(), $data['reason']);
                        Notification::make()->success()->title(__('admin.venues.activity_rejected'))->send();
                    }),
            ]);
    }
}
