<?php

namespace App\Filament\Resources\Providers\RelationManagers;

use App\Filament\Concerns\ModuleRelationManager;
use App\Filament\Support\Act;
use App\Filament\Support\Fmt;
use App\Models\ProviderMember;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** Provider team (managed by the provider in the app; admins can only (de)activate members). */
class MembersRelationManager extends RelationManager
{
    use ModuleRelationManager;

    protected static string $relationship = 'members';

    protected static string $permissionModule = 'providers';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.providers.members');
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('user'))
            ->columns([
                TextColumn::make('user.name')->label(__('admin.fields.name'))->weight('bold'),
                TextColumn::make('user.phone')->label(__('admin.fields.phone'))->placeholder('-')->formatStateUsing(fn ($state) => Fmt::phone($state)),
                TextColumn::make('user.email')->label(__('admin.fields.email'))->placeholder('-'),
                TextColumn::make('role')->label(__('admin.fields.role'))->badge()
                    ->formatStateUsing(fn ($state) => __('admin.member_roles.'.$state)),
                TextColumn::make('permissions')->label(__('admin.fields.permissions'))->badge()->color('gray')->placeholder('-'),
                ToggleColumn::make('is_active')->label(__('admin.fields.is_active'))
                    ->disabled(fn (ProviderMember $record) => $record->role === 'owner' || ! Act::can('providers.manage')),
                TextColumn::make('created_at')->label(__('admin.fields.created_at'))->date(),
            ]);
    }
}
