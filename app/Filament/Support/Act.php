<?php

namespace App\Filament\Support;

use App\Exceptions\BusinessException;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Illuminate\Validation\ValidationException;

/** Small helpers so every workflow action reports success / business errors the same way. */
class Act
{
    /** Run a service call; show a success toast, or a danger toast for business-rule violations. */
    public static function run(callable $callback, ?string $success = null): mixed
    {
        try {
            $result = $callback();
            Notification::make()->success()->title($success ?? __('admin.done'))->send();

            return $result;
        } catch (BusinessException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();
        } catch (ValidationException $e) {
            Notification::make()->danger()->title(collect($e->errors())->flatten()->first() ?? $e->getMessage())->send();
        }

        return null;
    }

    /** A confirm-with-reason action (reject / suspend / cancel ...). */
    public static function withReason(string $name, string $label, callable $handler, string $color = 'danger', string $icon = 'heroicon-o-x-circle'): Action
    {
        return Action::make($name)
            ->label($label)
            ->color($color)
            ->icon($icon)
            ->requiresConfirmation()
            ->schema([
                Textarea::make('reason')->label(__('admin.fields.reason'))->required()->maxLength(1000),
            ])
            ->action(fn (array $data, $record) => static::run(fn () => $handler($record, $data['reason'])));
    }

    public static function can(string $permission): bool
    {
        return (bool) auth()->user()?->can($permission);
    }
}
