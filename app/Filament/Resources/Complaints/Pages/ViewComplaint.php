<?php

namespace App\Filament\Resources\Complaints\Pages;

use App\Enums\ComplaintStatus;
use App\Filament\Resources\Complaints\ComplaintResource;
use App\Filament\Support\Act;
use App\Models\Complaint;
use App\Models\User;
use App\Services\ComplaintService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Pages\ViewRecord;

/** Complaint workflow; every change goes through ComplaintService (locks, validates, audits, notifies). */
class ViewComplaint extends ViewRecord
{
    protected static string $resource = ComplaintResource::class;

    protected function complaint(): Complaint
    {
        /** @var Complaint $record */
        $record = $this->getRecord();

        return $record;
    }

    protected function service(): ComplaintService
    {
        return app(ComplaintService::class);
    }

    /** Run a service call, then reload the record so the infolist and actions reflect the new state. */
    protected function runAndRefresh(callable $callback, ?string $success = null): void
    {
        Act::run($callback, $success);
        $this->complaint()->refresh();
    }

    protected function isClosed(): bool
    {
        return in_array($this->complaint()->status, [ComplaintStatus::Resolved, ComplaintStatus::Closed], true);
    }

    protected function getHeaderActions(): array
    {
        $can = fn () => ComplaintResource::canManage();

        return [
            Action::make('assign')->label(__('admin.actions.assign'))->icon('heroicon-o-user-plus')->color('gray')
                ->visible(fn () => $can() && $this->complaint()->status !== ComplaintStatus::Closed)
                ->fillForm(fn () => ['assigned_to' => $this->complaint()->assigned_to])
                ->schema([
                    Select::make('assigned_to')->label(__('admin.fields.assigned_to'))->options(fn () => ComplaintResource::adminOptions())->searchable(),
                ])
                ->action(fn (array $data) => $this->runAndRefresh(fn () => $this->service()->assign(
                    $this->complaint(),
                    filled($data['assigned_to'] ?? null) ? User::findOrFail($data['assigned_to']) : null,
                    auth()->user(),
                ))),

            Action::make('resolve')->label(__('admin.actions.resolve'))->icon('heroicon-o-check-circle')->color('success')
                ->visible(fn () => $can() && ! $this->isClosed())
                ->fillForm(fn () => ['resolution' => $this->complaint()->resolution])
                ->schema([
                    Textarea::make('resolution')->label(__('admin.fields.resolution'))->required()->rows(4)->maxLength(5000),
                ])
                ->modalSubmitActionLabel(__('admin.actions.resolve'))
                ->action(fn (array $data) => $this->runAndRefresh(fn () => $this->service()->resolve($this->complaint(), auth()->user(), $data['resolution']))),

            ActionGroup::make([
                Action::make('status')->label(__('admin.actions.change_status'))->icon('heroicon-o-arrows-right-left')
                    ->visible($can)
                    ->schema([
                        Select::make('status')->label(__('admin.fields.status'))->required()
                            ->options(fn () => collect(ComplaintService::TRANSITIONS[$this->complaint()->status->value] ?? [])
                                ->mapWithKeys(fn (string $s) => [$s => ComplaintStatus::from($s)->getLabel()])->all()),
                    ])
                    ->action(fn (array $data) => $this->runAndRefresh(fn () => $this->service()->changeStatus(
                        $this->complaint(), ComplaintStatus::from($data['status']), auth()->user(),
                    ))),

                Action::make('priority')->label(__('admin.actions.change_priority'))->icon('heroicon-o-flag')
                    ->visible($can)
                    ->fillForm(fn () => ['priority' => $this->complaint()->priority])
                    ->schema([
                        Select::make('priority')->label(__('admin.fields.priority'))->options(ComplaintResource::priorityOptions())->required(),
                    ])
                    ->action(fn (array $data) => $this->runAndRefresh(fn () => $this->service()->setPriority($this->complaint(), $data['priority'], auth()->user()))),

                Action::make('close')->label(__('admin.actions.close'))->icon('heroicon-o-lock-closed')->color('danger')
                    ->visible(fn () => $can() && $this->complaint()->status !== ComplaintStatus::Closed)
                    ->requiresConfirmation()
                    ->action(fn () => $this->runAndRefresh(fn () => $this->service()->close($this->complaint(), auth()->user()))),
            ]),
        ];
    }
}
