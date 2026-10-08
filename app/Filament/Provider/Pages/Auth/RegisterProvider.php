<?php

namespace App\Filament\Provider\Pages\Auth;

use App\Enums\ProviderType;
use App\Enums\UserStatus;
use App\Enums\UserType;
use App\Filament\Support\Fmt;
use App\Models\City;
use App\Models\Sector;
use App\Models\User;
use App\Services\OtpService;
use App\Services\ProviderService;
use Filament\Auth\Pages\Register;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use SensitiveParameter;

/**
 * Sign-up for NEW providers: creates the user account and the provider
 * (ProviderService::register → status pending), then signs the user in.
 */
class RegisterProvider extends Register
{
    protected Width|string|null $maxWidth = Width::FourExtraLarge;

    private const PROVIDER_FIELDS = [
        'type', 'name_ar', 'name_en', 'national_id', 'cr_number', 'legal_name', 'vat_number',
        'city_id', 'sector_ids', 'bank_name', 'iban', 'account_holder',
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
        $isType = fn (string $type) => fn (Get $get) => in_array($get('type'), [$type, ProviderType::from($type)], true);

        return $schema->components([
            Section::make(__('provider.register.account'))->columns(2)->schema([
                $this->getNameFormComponent()->label(__('provider.fields.full_name')),
                $this->getEmailFormComponent(),
                TextInput::make('phone')->label(__('provider.fields.mobile'))->tel()->required()
                    ->regex('/^(\+?966|0)?5\d{8}$/')->placeholder('05XXXXXXXX')
                    ->dehydrateStateUsing(fn ($state) => $state ? OtpService::normalize($state) : null)
                    ->rule(fn () => function (string $attribute, $value, \Closure $fail) {
                        if ($value && User::withTrashed()->where('phone', OtpService::normalize($value))->exists()) {
                            $fail(__('provider.register.phone_taken'));
                        }
                    }),
                $this->getPasswordFormComponent(),
                $this->getPasswordConfirmationFormComponent(),
            ]),
            Section::make(__('provider.register.business'))->columns(2)->schema([
                Radio::make('type')->label(__('provider.fields.provider_type'))->options(ProviderType::class)
                    ->default(ProviderType::Establishment->value)->required()->inline()->live()->columnSpanFull(),
                TextInput::make('name_ar')->label(__('provider.fields.business_name_ar'))->required()->maxLength(150),
                TextInput::make('name_en')->label(__('provider.fields.business_name_en'))->maxLength(150),
                TextInput::make('cr_number')->label(__('provider.fields.cr_number'))->rule('digits:10')
                    ->visible($isType('establishment'))->required($isType('establishment')),
                TextInput::make('national_id')->label(__('provider.fields.national_id'))->rule('digits:10')
                    ->visible($isType('individual'))->required($isType('individual')),
                TextInput::make('legal_name')->label(__('provider.fields.legal_name'))->maxLength(200),
                TextInput::make('vat_number')->label(__('provider.fields.vat_number'))->rule('digits:15'),
                Select::make('city_id')->label(__('provider.fields.city'))->required()->searchable()
                    ->options(fn () => City::where('is_active', true)->orderBy('sort_order')->pluck(Fmt::col('name'), 'id')),
                CheckboxList::make('sector_ids')->label(__('provider.fields.sectors'))->required()->columns(2)->columnSpanFull()
                    ->options(fn () => Sector::where('is_active', true)->orderBy('sort_order')->pluck(Fmt::col('name'), 'id'))
                    ->helperText(__('provider.register.sectors_hint')),
            ]),
            Section::make(__('provider.register.bank'))->columns(3)->collapsible()->schema([
                TextInput::make('bank_name')->label(__('provider.fields.bank_name'))->maxLength(100),
                TextInput::make('account_holder')->label(__('provider.fields.account_holder'))->maxLength(150),
                TextInput::make('iban')->label(__('provider.fields.iban'))->regex('/^SA\d{22}$/')->placeholder('SA0000000000000000000000'),
            ]),
        ]);
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
        $provider['email'] = $user->email;
        $provider['phone'] = $user->phone;
        $provider['sector_ids'] = array_map('intval', (array) ($provider['sector_ids'] ?? []));

        app(ProviderService::class)->register($user, array_filter($provider, fn ($v) => $v !== null && $v !== ''));

        return $user->fresh();
    }
}
