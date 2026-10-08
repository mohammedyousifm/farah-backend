<?php

namespace App\Filament\Resources\Complaints\RelationManagers;

use App\Enums\ComplaintStatus;
use App\Filament\Concerns\ModuleRelationManager;
use App\Filament\Support\Act;
use App\Models\Complaint;
use App\Services\ComplaintService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** The complaint thread: customer messages, public admin replies and internal notes. */
class RepliesRelationManager extends RelationManager
{
    use ModuleRelationManager;

    protected static string $relationship = 'replies';

    protected static string $permissionModule = 'complaints';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.complaints.thread');
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('user'))
            ->defaultSort('created_at', 'desc')
            ->paginated([10, 25, 50])
            ->columns([
                TextColumn::make('user.name')->label(__('admin.fields.author'))->placeholder(__('admin.system'))->weight('bold')
                    ->description(fn ($record) => $record->created_at?->diffForHumans()),
                TextColumn::make('body')->label(__('admin.fields.message'))->wrap(),
                IconColumn::make('is_internal')->label(__('admin.fields.internal_note'))->boolean()
                    ->trueIcon('heroicon-o-lock-closed')->falseIcon('heroicon-o-globe-alt')
                    ->trueColor('warning')->falseColor('success'),
            ])
            ->headerActions([
                Action::make('reply')->label(__('admin.actions.reply'))->icon('heroicon-o-chat-bubble-left-ellipsis')
                    ->visible(fn () => Act::can('complaints.manage') && $this->getOwnerRecord()->status !== ComplaintStatus::Closed)
                    ->schema([
                        Textarea::make('body')->label(__('admin.fields.message'))->required()->rows(5)->maxLength(5000),
                        Toggle::make('is_internal')->label(__('admin.fields.internal_note'))
                            ->helperText(__('admin.hints.internal_note')),
                    ])
                    ->action(function (array $data) {
                        /** @var Complaint $complaint */
                        $complaint = $this->getOwnerRecord();

                        Act::run(fn () => app(ComplaintService::class)->reply(
                            $complaint, auth()->user(), $data['body'], (bool) ($data['is_internal'] ?? false),
                        ), __('admin.complaints.reply_sent'));
                    }),
            ]);
    }
}
