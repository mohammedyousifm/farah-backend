<?php

namespace App\Filament\Provider\Resources\Venues\RelationManagers;

use App\Filament\Provider\Concerns\ProviderRelationManager;
use App\Filament\Provider\Resources\Venues\VenueResource;
use App\Filament\Support\Fmt;
use App\Models\Activity;
use App\Models\Resource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The bookable units of each activity (table 1..10, lane 1..4, ...). Every resource
 * has its own availability, so the number of resources is how many customers can
 * play the activity at the same time.
 */
class ResourcesRelationManager extends RelationManager
{
    use ProviderRelationManager;

    protected static string $relationship = 'resources';

    protected static string $area = 'venues';

    protected static ?string $managePermission = 'venues.manage';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('provider.fields.resources');
    }

    protected function canModifyOwner(): bool
    {
        return ! in_array($this->getOwnerRecord()->status, VenueResource::LOCKED, true);
    }

    /** @return array<int, string> the venue's activities */
    private function activityOptions(): array
    {
        return $this->getOwnerRecord()->activities()->get()->mapWithKeys(fn (Activity $a) => [$a->id => Fmt::tr($a, 'name')])->all();
    }

    private function activity(mixed $id): Activity
    {
        return $this->getOwnerRecord()->activities()->findOrFail((int) $id);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Select::make('activity_id')->label(__('provider.fields.activity'))->required()->options(fn () => $this->activityOptions())
                ->columnSpanFull(),
            TextInput::make('name_ar')->label(__('provider.fields.name_ar'))->required()->maxLength(120)->placeholder(__('provider.venues.resource_placeholder')),
            TextInput::make('name_en')->label(__('provider.fields.name_en'))->maxLength(120),
            TextInput::make('sort_order')->label(__('provider.fields.sort_order'))->integer()->minValue(0)->default(0),
            Toggle::make('is_active')->label(__('provider.fields.is_active'))->default(true)->inline(false),
        ]);
    }

    /** Fields of the "add N numbered resources" action (shared with the activities table). */
    public static function bulkSchema(): array
    {
        return [
            TextInput::make('count')->label(__('provider.fields.resources_count'))->integer()->required()->minValue(1)->maxValue(50)->default(5),
            TextInput::make('prefix_ar')->label(__('provider.fields.prefix_ar'))->required()->maxLength(100)->default('طاولة')
                ->helperText(__('provider.venues.prefix_hint')),
            TextInput::make('prefix_en')->label(__('provider.fields.prefix_en'))->maxLength(100)->default('Table'),
        ];
    }

    /** Create numbered resources ("طاولة 1" .. "طاولة N"), continuing after the existing ones. */
    public static function bulkCreate(Activity $activity, array $data): int
    {
        $count = max(1, min(50, (int) ($data['count'] ?? 1)));
        $existing = $activity->resources()->count();
        $sort = (int) $activity->resources()->max('sort_order');

        for ($i = 1; $i <= $count; $i++) {
            $number = $existing + $i;
            $activity->resources()->create([
                'name_ar' => trim($data['prefix_ar']).' '.$number,
                'name_en' => filled($data['prefix_en'] ?? null) ? trim($data['prefix_en']).' '.$number : null,
                'sort_order' => $sort + $i,
                'is_active' => true,
            ]);
        }

        return $count;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('activity')->orderBy('resources.activity_id')->orderBy('resources.sort_order')->orderBy('resources.id'))
            ->emptyStateHeading(__('provider.venues.no_resources'))
            ->emptyStateDescription(__('provider.venues.no_resources_hint'))
            ->columns([
                TextColumn::make('name_ar')->label(__('provider.fields.name'))->weight('bold')
                    ->state(fn (Resource $r) => Fmt::tr($r, 'name')),
                TextColumn::make('activity.name_ar')->label(__('provider.fields.activity'))
                    ->state(fn (Resource $r) => Fmt::tr($r->activity, 'name'))->badge()->color('gray'),
                IconColumn::make('is_active')->label(__('provider.fields.is_active'))->boolean(),
            ])
            ->filters([
                SelectFilter::make('activity_id')->label(__('provider.fields.activity'))->options(fn () => $this->activityOptions())
                    ->query(fn (Builder $query, array $data) => $query->when($data['value'] ?? null, fn ($q, $id) => $q->where('resources.activity_id', $id))),
            ])
            ->headerActions([
                Action::make('add_many')->label(__('provider.venues.add_resources'))->icon('heroicon-o-squares-plus')
                    ->visible(fn () => $this->canWrite())
                    ->modalDescription(__('provider.venues.add_resources_hint'))
                    ->schema(fn () => [
                        Select::make('activity_id')->label(__('provider.fields.activity'))->required()->options($this->activityOptions())
                            ->default(array_key_first($this->activityOptions())),
                        ...static::bulkSchema(),
                    ])
                    ->action(function (array $data) {
                        $count = static::bulkCreate($this->activity($data['activity_id']), $data);
                        Notification::make()->success()->title(__('provider.venues.resources_added', ['count' => $count]))->send();
                    }),
                CreateAction::make()->label(__('provider.venues.add_resource'))
                    ->using(fn (array $data) => $this->activity($data['activity_id'])->resources()->create(collect($data)->except('activity_id')->all())),
            ])
            ->recordActions([
                EditAction::make()->using(function (Resource $record, array $data) {
                    $record->update(['activity_id' => $this->activity($data['activity_id'])->id] + $data);

                    return $record;
                }),
                DeleteAction::make()->using(function (Resource $record) {
                    if ($record->bookings()->exists()) {
                        Notification::make()->danger()->title(__('provider.venues.resource_has_bookings'))->send();

                        throw new Halt;
                    }

                    return $record->delete();
                }),
            ]);
    }
}
