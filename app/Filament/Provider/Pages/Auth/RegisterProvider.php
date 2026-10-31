<?php

namespace App\Filament\Provider\Pages\Auth;

use App\Enums\ProviderType;
use App\Enums\UserStatus;
use App\Enums\UserType;
use App\Filament\Concerns\KeepsDraft;
use App\Filament\Support\Fmt;
use App\Filament\Support\SaudiInput;
use App\Models\City;
use App\Models\Plan;
use App\Models\Sector;
use App\Models\User;
use App\Rules\GoogleMapsLink;
use App\Services\OtpService;
use App\Services\ProviderService;
use App\Services\SubscriptionService;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Filament\Auth\Events\Registered;
use Filament\Auth\Http\Responses\Contracts\RegistrationResponse;
use Filament\Auth\Pages\Register;
use Filament\Facades\Filament;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use SensitiveParameter;

/**
 * Sign-up for NEW providers: the plan first, then the user account and the provider
 * (ProviderService::register → status pending), then signs the user in.
 */
class RegisterProvider extends Register
{
    use KeepsDraft;

    protected Width|string|null $maxWidth = Width::FourExtraLarge;

    private const PROVIDER_FIELDS = [
        'name_ar', 'name_en', 'cr_number', 'legal_name', 'vat_number',
        'city_id', 'maps_url', 'website', 'sector_ids', 'bank_name', 'iban', 'account_holder',
    ];

    public function getTitle(): string|Htmlable
    {
        return __('provider.register.title');
    }

    public function getHeading(): string|Htmlable|null
    {
        return __('provider.register.heading');
    }

    public function form(Schema $schema): Schema
    {
        $plans = Plan::active()->with('items')->orderByDesc('is_free')->orderBy('sort_order')->orderBy('price')->get();

        return $schema->components([
            // The plan is chosen before the account exists (here, or from the website's ?plan= link). A paid plan
            // becomes a request that starts once the provider is approved and has paid; until then: the free plan.
            Section::make(__('provider.register.plan'))->description(__('provider.register.plan_hint'))
                ->visible($plans->isNotEmpty())
                ->schema([
                    Radio::make('plan_id')->hiddenLabel()->required($plans->isNotEmpty())
                        ->options($plans->mapWithKeys(fn (Plan $p) => [$p->id => $p->tr('name').' · '.($p->is_free
                            ? __('provider.plans.free')
                            : Fmt::sar($p->price).' / '.__('provider.plans.for_days', ['days' => $p->duration_days]))]))
                        ->descriptions($plans->mapWithKeys(fn (Plan $p) => [$p->id => $p->items
                            ->map(fn ($item) => $item->feature->getLabel().($item->value ? ' ('.__('provider.plans.up_to', ['count' => $item->value]).')' : ''))
                            ->implode('، ')]))
                        ->default(fn () => $plans->firstWhere('id', (int) request()->query('plan'))?->id ?? $plans->firstWhere('is_free', true)?->id)
                        ->in($plans->pluck('id')->all()),
                ]),
            Section::make(__('provider.register.account'))->columns(2)->schema([
                $this->checkOnBlur($this->getNameFormComponent()->label(__('provider.fields.full_name'))),
                $this->checkOnBlur($this->getEmailFormComponent()),
                $this->checkOnBlur(SaudiInput::mobile()->label(__('provider.fields.mobile'))->required()
                    ->rule(fn () => function (string $attribute, $value, \Closure $fail) {
                        if ($value && User::withTrashed()->where('phone', OtpService::normalize($value))->exists()) {
                            $fail(__('provider.register.phone_taken'));
                        }
                    })),
                $this->getPasswordFormComponent(),
                $this->getPasswordConfirmationFormComponent(),
            ]),
            Section::make(__('provider.register.business'))->columns(2)->schema([
                TextInput::make('name_ar')->label(__('provider.fields.business_name_ar'))->required()->maxLength(150)
                    ->helperText(__('provider.register.business_name_hint')),
                TextInput::make('name_en')->label(__('provider.fields.business_name_en'))->maxLength(150),
                $this->checkOnBlur(TextInput::make('cr_number')->label(__('provider.fields.cr_number'))->rule('digits:10')->required()
                    ->extraInputAttributes(['dir' => 'ltr', 'inputmode' => 'numeric', 'maxlength' => 10])),
                TextInput::make('legal_name')->label(__('provider.fields.legal_name'))->maxLength(200),
                $this->checkOnBlur(TextInput::make('vat_number')->label(__('provider.fields.vat_number'))->rule('digits:15')
                    ->extraInputAttributes(['dir' => 'ltr', 'inputmode' => 'numeric', 'maxlength' => 15])),
                Select::make('city_id')->label(__('provider.fields.city'))->required()->searchable()
                    ->options(fn () => City::where('is_active', true)->orderBy('sort_order')->pluck(Fmt::col('name'), 'id')),
                $this->checkOnBlur(TextInput::make('maps_url')->label(__('provider.fields.maps_url'))->url()->rule('url:http,https')->required()->maxLength(500)
                    ->rule(new GoogleMapsLink)->placeholder('https://maps.app.goo.gl/...')
                    ->helperText(__('provider.register.maps_url_hint'))->extraInputAttributes(['dir' => 'ltr'])),
                $this->checkOnBlur(TextInput::make('website')->label(__('provider.register.website_optional'))->url()->rule('url:http,https')->maxLength(200)
                    ->placeholder('https://')->extraInputAttributes(['dir' => 'ltr'])),
                CheckboxList::make('sector_ids')->label(__('provider.fields.sectors'))->required()->columns(2)->columnSpanFull()
                    ->options(fn () => Sector::where('is_active', true)->orderBy('sort_order')->pluck(Fmt::col('name'), 'id'))
                    ->helperText(__('provider.register.sectors_hint')),
            ]),
            Section::make(__('provider.register.bank'))->columns(3)->collapsible()->schema([
                TextInput::make('bank_name')->label(__('provider.fields.bank_name'))->maxLength(100),
                TextInput::make('account_holder')->label(__('provider.fields.account_holder'))->maxLength(150),
                $this->checkOnBlur(SaudiInput::iban()->label(__('provider.fields.iban'))),
            ]),
            View::make('filament.keep-draft'),
        ]);
    }

    public function mount(): void
    {
        parent::mount();
        $this->restoreDraft();
    }

    protected function draftExcept(): array
    {
        return ['password', 'passwordConfirmation'];
    }

    /** Errors show as soon as the person leaves a field, not only after pressing "create account". */
    private function checkOnBlur(Field $field): Field
    {
        return $field->live(onBlur: true)
            ->afterStateUpdated(fn (Field $component) => $this->validateOnly($component->getStatePath()));
    }

    protected function getPasswordFormComponent(): Component
    {
        // The match with the confirmation is checked on the confirmation field, so leaving this one first
        // does not already say "does not match".
        return $this->checkOnBlur(TextInput::make('password')
            ->label(__('filament-panels::auth/pages/register.form.password.label'))
            ->password()->revealable(filament()->arePasswordsRevealable())->required()
            ->rule(Password::default())->showAllValidationMessages()
            ->validationAttribute(__('filament-panels::auth/pages/register.form.password.validation_attribute'))
            ->helperText(__('provider.register.password_hint'))
            ->extraInputAttributes(['dir' => 'ltr', 'autocomplete' => 'new-password'])
            ->dehydrateStateUsing(fn (#[SensitiveParameter] $state) => Hash::make($state)));
    }

    protected function getPasswordConfirmationFormComponent(): Component
    {
        return $this->checkOnBlur(TextInput::make('passwordConfirmation')
            ->label(__('filament-panels::auth/pages/register.form.password_confirmation.label'))
            ->password()->revealable(filament()->arePasswordsRevealable())->required()
            ->same('password')
            ->extraInputAttributes(['dir' => 'ltr', 'autocomplete' => 'new-password'])
            ->dehydrated(false));
    }

    /**
     * Filament counts every press of the button, mistakes included, and stops at two a minute. Here the form is
     * checked first (a mistake costs no try) and a complete form gets five tries a minute.
     */
    public function register(): ?RegistrationResponse
    {
        $this->form->validate();

        try {
            $this->rateLimit(5);
        } catch (TooManyRequestsException $exception) {
            $this->getRateLimitedNotification($exception)?->send();

            return null;
        }

        $user = $this->wrapInDatabaseTransaction(function (): Model {
            $this->callHook('beforeValidate');
            $data = $this->form->getState();
            $this->callHook('afterValidate');

            $data = $this->mutateFormDataBeforeRegister($data);

            $this->callHook('beforeRegister');
            $user = $this->handleRegistration($data);
            $this->form->model($user)->saveRelationships();
            $this->callHook('afterRegister');

            return $user;
        });

        event(new Registered($user));

        $this->sendEmailVerificationNotification($user);

        Filament::auth()->login($user);

        session()->regenerate();

        return app(RegistrationResponse::class);
    }

    protected function handleRegistration(#[SensitiveParameter] array $data): Model
    {
        $user = User::create([
            'name' => $data['name'],
            'email' => strtolower($data['email']),
            'phone' => $data['phone'] ?? null,
            'password' => $data['password'],
            'type' => UserType::Customer,
            'status' => UserStatus::Active,
            'locale' => app()->getLocale(),
        ]);

        $provider = Arr::only($data, self::PROVIDER_FIELDS);
        $provider['type'] = ProviderType::Establishment->value;
        $provider['email'] = $user->email;
        $provider['phone'] = $user->phone;
        $provider['sector_ids'] = array_map('intval', (array) ($provider['sector_ids'] ?? []));

        $created = app(ProviderService::class)->register($user, array_filter($provider, fn ($v) => $v !== null && $v !== ''));

        // A paid plan picked at sign-up goes to the platform as a request; the free plan needs nothing.
        $plan = Plan::active()->find($data['plan_id'] ?? null);
        if ($plan && ! $plan->is_free) {
            app(SubscriptionService::class)->request($created, $plan, $user);
        }

        $this->forgetDraft();

        return $user->fresh();
    }
}
