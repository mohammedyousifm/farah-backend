<?php

namespace App\Filament\Resources\Venues\RelationManagers;

use App\Enums\BookingStatus;
use App\Filament\Concerns\ModuleRelationManager;
use App\Filament\Resources\Bookings\BookingResource;
use App\Models\Booking;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class BookingsRelationManager extends RelationManager
{
    use ModuleRelationManager;

    protected static string $relationship = 'bookings';

    protected static string $permissionModule = 'orders';

    public function isReadOnly(): bool
    {
        return true;
    }

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.resources.bookings.plural');
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['activity', 'resource', 'user', 'order']))
            ->defaultSort('starts_at', 'desc')
            ->recordUrl(fn (Booking $record) => BookingResource::getUrl('view', ['record' => $record]))
            ->columns(BookingResource::columns(venue: false))
            ->filters([
                SelectFilter::make('status')->label(__('admin.fields.status'))->options(BookingStatus::class),
            ]);
    }
}
