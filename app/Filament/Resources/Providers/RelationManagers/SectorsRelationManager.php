<?php

namespace App\Filament\Resources\Providers\RelationManagers;

use App\Filament\Concerns\ModuleRelationManager;
use App\Filament\Support\Act;
use App\Filament\Support\Fmt;
use App\Models\Sector;
use Filament\Actions\Action;
use Filament\Actions\AttachAction;
use Filament\Actions\DetachAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** Activity types the provider operates in; the pivot status says whether it may offer activities there. */
class SectorsRelationManager extends RelationManager
{
    use ModuleRelationManager;

    protected static string $relationship = 'sectors';

    protected static string $permissionModule = 'providers';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.resources.sectors.plural');
    }

    private function setStatus(Sector $sector, string $status): void
    {
        $this->getOwnerRecord()->sectors()->updateExistingPivot($sector->id, ['status' => $status]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name_ar')
            ->columns([
                TextColumn::make('name_ar')->formatStateUsing(Fmt::localized('name'))->label(__('admin.fields.name'))->weight('bold')->description(fn (Sector $r) => $r->name_en),
                IconColumn::make('requires_license')->label(__('admin.fields.requires_license'))->boolean(),
                TextColumn::make('pivot.status')->label(__('admin.fields.status'))->badge()
                    ->formatStateUsing(fn ($state) => __('admin.sector_status.'.$state))
                    ->color(fn ($state) => match ($state) {
                        'approved' => 'success',
                        'rejected' => 'danger',
                        default => 'warning',
                    }),
                TextColumn::make('pivot.created_at')->label(__('admin.fields.created_at'))->date(),
            ])
            ->headerActions([
                AttachAction::make()->preloadRecordSelect()
                    ->mutateDataUsing(fn (array $data) => $data + ['status' => 'approved']),
            ])
            ->recordActions([
                Action::make('approve_sector')->label(__('admin.actions.approve'))->icon('heroicon-o-check-circle')->color('success')
                    ->visible(fn (Sector $record) => $record->pivot?->status !== 'approved' && Act::can('providers.manage'))
                    ->requiresConfirmation()
                    ->action(fn (Sector $record) => Act::run(fn () => $this->setStatus($record, 'approved'))),
                Action::make('reject_sector')->label(__('admin.actions.reject'))->icon('heroicon-o-x-circle')->color('danger')
                    ->visible(fn (Sector $record) => $record->pivot?->status !== 'rejected' && Act::can('providers.manage'))
                    ->requiresConfirmation()
                    ->action(fn (Sector $record) => Act::run(fn () => $this->setStatus($record, 'rejected'))),
                DetachAction::make(),
            ]);
    }
}
