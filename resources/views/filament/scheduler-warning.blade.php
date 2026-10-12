{{-- Shown at the top of the admin dashboard when the background scheduler has not run for ten minutes:
     without it unpaid orders never expire, bookings never complete and rating requests are never sent. --}}
@if (! \App\Support\SchedulerHeartbeat::isFresh() && auth()->user()?->hasRole('super_admin'))
    <div style="margin: 0 0 1rem; padding: .75rem 1rem; border-radius: .75rem; background: #FEF3C7; color: #92400E; font-size: .9rem; display: flex; gap: .6rem; align-items: flex-start;">
        <x-filament::icon icon="heroicon-o-exclamation-triangle" style="width: 1.25rem; height: 1.25rem; flex-shrink: 0;" />
        <span>{{ __('admin.scheduler_stale') }}</span>
    </div>
@endif
