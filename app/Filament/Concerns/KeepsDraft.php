<?php

namespace App\Filament\Concerns;

use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;

/**
 * Long forms (store sign-up, adding the store) keep what was typed on the server for a week: a refresh, a closed tab
 * or signing out and back in brings it back. Signed-in people get it on any device; before sign-up it is tied to the
 * browser session. Passwords and uploads are never kept. The draft is gone once the form is sent.
 */
trait KeepsDraft
{
    /** Form keys that are never stored (passwords, uploads). */
    protected function draftExcept(): array
    {
        return [];
    }

    protected function draftKey(): string
    {
        $who = auth()->id() ? 'u'.auth()->id() : 's'.session()->getId();

        return 'form-draft:'.class_basename(static::class).':'.(Filament::getTenant()?->getKey() ?? '-').':'.$who;
    }

    /** Livewire: any change under $data (each step's "next", every live field, every blur). */
    public function updatedData(): void
    {
        $this->saveDraft();
    }

    protected function saveDraft(): void
    {
        $draft = Arr::except($this->data ?? [], $this->draftExcept());
        if (array_filter($draft, fn ($v) => filled($v)) !== []) {
            Cache::put($this->draftKey(), $draft, now()->addDays(7));
        }
    }

    protected function restoreDraft(): void
    {
        $draft = Cache::get($this->draftKey());
        if (! is_array($draft) || $draft === []) {
            return;
        }

        $this->form->fill(array_replace($this->form->getRawState(), $draft));
        Notification::make()->info()->title(__('provider.draft.restored'))->send();
    }

    protected function forgetDraft(): void
    {
        Cache::forget($this->draftKey());
    }
}
