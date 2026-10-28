<?php

namespace App\Filament\Pages;

use App\Filament\NavGroup;
use App\Models\Setting;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Platform settings (config('farah.defaults') overridden by the settings table).
 *
 * @property-read Schema $form
 */
class Settings extends Page
{
    protected static string|\UnitEnum|null $navigationGroup = NavGroup::System;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static ?int $navigationSort = 30;

    protected static ?string $slug = 'settings';

    /** key => [group, cast] */
    public const FIELDS = [
        'vat_rate' => ['finance', 'float'],
        'prices_include_vat' => ['finance', 'bool'],
        'settlement_hold_days' => ['finance', 'int'],
        'settlement_cycle' => ['finance', 'string'],
        'settlement_min_amount' => ['finance', 'float'],
        'order_payment_timeout_minutes' => ['orders', 'int'],
        'max_bookings_per_order' => ['orders', 'int'],
        'document_expiry_warning_days' => ['providers', 'int'],
        'support_phone' => ['app', 'string'],
        'support_email' => ['app', 'string'],
        'platform_name_ar' => ['app', 'string'],
        'platform_name_en' => ['app', 'string'],
        'maintenance_mode' => ['app', 'bool'],
        'min_app_version_ios' => ['app', 'string'],
        'min_app_version_android' => ['app', 'string'],
    ];

    public ?array $data = [];

    public static function getNavigationLabel(): string
    {
        return __('admin.settings.title');
    }

    public function getTitle(): string
    {
        return __('admin.settings.title');
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return (bool) ($user?->can('settings.view') || $user?->can('settings.manage'));
    }

    public static function canManage(): bool
    {
        return (bool) auth()->user()?->can('settings.manage');
    }

    public function mount(): void
    {
        $this->form->fill(collect(self::FIELDS)->mapWithKeys(fn ($def, $key) => [$key => Setting::get($key)])->all());
    }

    public function form(Schema $schema): Schema
    {
        $label = fn (string $key) => __("admin.settings.fields.{$key}");

        return $schema
            ->statePath('data')
            ->disabled(fn () => ! static::canManage())
            ->columns(2)
            ->components([
                Section::make(__('admin.settings.groups.finance'))->icon('heroicon-o-banknotes')->columns(2)->schema([
                    TextInput::make('vat_rate')->label($label('vat_rate'))->numeric()->minValue(0)->maxValue(100)->suffix('%')->required(),
                    Toggle::make('prices_include_vat')->label($label('prices_include_vat'))->inline(false),
                    TextInput::make('settlement_hold_days')->label($label('settlement_hold_days'))->integer()->minValue(0)->maxValue(90)->required()
                        ->helperText(__('admin.settings.hints.settlement_hold_days')),
                    Select::make('settlement_cycle')->label($label('settlement_cycle'))->required()
                        ->options(collect(['weekly', 'biweekly', 'monthly'])->mapWithKeys(fn ($c) => [$c => __("admin.settings.cycles.{$c}")])),
                    TextInput::make('settlement_min_amount')->label($label('settlement_min_amount'))->numeric()->minValue(0)
                        ->suffix(__('admin.currency'))->required(),
                ]),
                Section::make(__('admin.settings.groups.orders'))->icon('heroicon-o-shopping-bag')->columns(2)->schema([
                    TextInput::make('order_payment_timeout_minutes')->label($label('order_payment_timeout_minutes'))->integer()->minValue(1)->maxValue(1440)->required(),
                    TextInput::make('max_bookings_per_order')->label($label('max_bookings_per_order'))->integer()->minValue(1)->maxValue(100)->required(),
                ]),
                Section::make(__('admin.settings.groups.providers'))->icon('heroicon-o-building-storefront')->columns(2)->schema([
                    TextInput::make('document_expiry_warning_days')->label($label('document_expiry_warning_days'))->integer()->minValue(1)->maxValue(365)->required(),                ]),
                Section::make(__('admin.settings.groups.app'))->icon('heroicon-o-device-phone-mobile')->columns(2)->schema([
                    TextInput::make('platform_name_ar')->label($label('platform_name_ar'))->required(),
                    TextInput::make('platform_name_en')->label($label('platform_name_en'))->required(),
                    TextInput::make('support_phone')->label($label('support_phone'))->tel(),
                    TextInput::make('support_email')->label($label('support_email'))->email(),
                    TextInput::make('min_app_version_ios')->label($label('min_app_version_ios'))->regex('/^\d+\.\d+\.\d+$/'),
                    TextInput::make('min_app_version_android')->label($label('min_app_version_android'))->regex('/^\d+\.\d+\.\d+$/'),
                    Toggle::make('maintenance_mode')->label($label('maintenance_mode'))->inline(false)
                        ->helperText(__('admin.settings.hints.maintenance_mode')),
                ]),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('save')
                ->footer([
                    Actions::make([
                        Action::make('save')->label(__('admin.actions.save'))->submit('save')->keyBindings(['mod+s'])
                            ->visible(fn () => static::canManage()),
                    ]),
                ]),
        ]);
    }

    public function save(): void
    {
        abort_unless(static::canManage(), 403);

        $state = $this->form->getState();

        foreach (self::FIELDS as $key => [$group, $cast]) {
            $value = $state[$key] ?? null;
            $value = match ($cast) {
                'int' => $value === null || $value === '' ? null : (int) $value,
                'float' => $value === null || $value === '' ? null : (float) $value,
                'bool' => (bool) $value,
                default => $value === null ? null : (string) $value,
            };
            Setting::put($key, $value, $group);
        }

        Notification::make()->success()->title(__('admin.settings.saved'))->send();
    }
}
