<?php

namespace App\Filament\Resources\Orders\RelationManagers;

use App\Filament\Concerns\ModuleRelationManager;
use App\Filament\Resources\Bookings\BookingResource;
use App\Models\Booking;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** What the order pays for: one row per reserved resource and time window. */
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
            ->modifyQueryUsing(fn ($query) => $query->with(['activity', 'resource', 'user', 'order', 'checkedInBy']))
            ->paginated(false)
            ->recordUrl(fn (Booking $record) => BookingResource::getUrl('view', ['record' => $record]))
            ->columns(BookingResource::columns(venue: false, order: false));
    }
}
