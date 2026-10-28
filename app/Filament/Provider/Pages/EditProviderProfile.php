<?php

namespace App\Filament\Provider\Pages;

use App\Filament\Provider\Support\ProviderAccess;
use App\Filament\Support\Fmt;
use App\Filament\Support\Upload;
use App\Models\City;
use App\Models\Provider;
use App\Rules\GoogleMapsLink;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Tenancy\EditTenantProfile;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * Provider profile (tenant profile page). Same editable fields as the provider API
 * (UpdateProviderProfileRequest); legal identity fields are shown read-only.
 */
class EditProviderProfile extends EditTenantProfile
{
    public static function getLabel(): string
    {
        return __('provider.profile.title');
    }

    public static function canView(Model $tenant): bool
    {
        return ProviderAccess::canView('profile', 'profile.manage');
    }

    public function mount(): void
    {
        $this->tenant = Filament::getTenant();

        abort_unless(static::canView($this->tenant), 403);

        $this->fillForm();
    }

    public function hydrate(): void
    {
        abort_unless(static::canView($this->tenant), 403);
    }

    private function readOnly(): bool
    {
        return ! ProviderAccess::canManage('profile', 'profile.manage');
    }

    public function form(Schema $schema): Schema
    {
        /** @var Provider $provider */
        $provider = $this->tenant;
        $dir = 'providers/'.$provider->id;

        return $schema->disabled(fn () => $this->readOnly())->components([
            Section::make(__('provider.profile.identity'))->columns(2)->schema([
                TextInput::make('name_ar')->label(__('provider.fields.name_ar'))->required()->maxLength(150),
                TextInput::make('name_en')->label(__('provider.fields.name_en'))->maxLength(150),
                Textarea::make('bio_ar')->label(__('provider.fields.bio_ar'))->rows(4)->maxLength(3000),
                Textarea::make('bio_en')->label(__('provider.fields.bio_en'))->rows(4)->maxLength(3000),
                FileUpload::make('logo')->label(__('provider.fields.logo'))->acceptedFileTypes(Upload::IMAGES)->disk('public')->directory($dir)->maxSize(4096),
                FileUpload::make('cover')->label(__('provider.fields.cover'))->acceptedFileTypes(Upload::IMAGES)->disk('public')->directory($dir)->maxSize(8192),
            ]),
            Section::make(__('provider.profile.legal'))->columns(3)->schema([
                Text::make(fn () => __('provider.fields.status').': '.$provider->status?->getLabel()),
                Text::make(fn () => __('provider.fields.cr_number').': '.($provider->cr_number ?? '-')),
                TextInput::make('vat_number')->label(__('provider.fields.vat_number'))->rule('digits:15'),
            ]),
            Section::make(__('provider.profile.contact'))->columns(2)->schema([
                TextInput::make('phone')->label(__('provider.fields.phone'))->tel()->maxLength(20),
                TextInput::make('email')->label(__('provider.fields.email'))->email()->maxLength(120),
                TextInput::make('website')->label(__('provider.fields.website'))->url()->rule('url:http,https')->maxLength(200),
                TextInput::make('maps_url')->label(__('provider.fields.maps_url'))->url()->rule('url:http,https')->maxLength(500)->rule(new GoogleMapsLink)
                    ->placeholder('https://maps.app.goo.gl/...'),
                Select::make('city_id')->label(__('provider.fields.city'))->required()->searchable()
                    ->options(fn () => City::orderBy('sort_order')->pluck(Fmt::col('name'), 'id')),
                TextInput::make('address')->label(__('provider.fields.address'))->maxLength(255)->columnSpanFull(),
                TextInput::make('lat')->label(__('provider.fields.lat'))->numeric()->minValue(-90)->maxValue(90),
                TextInput::make('lng')->label(__('provider.fields.lng'))->numeric()->minValue(-180)->maxValue(180),
            ]),
            Section::make(__('provider.profile.bank'))->columns(3)->schema([
                TextInput::make('bank_name')->label(__('provider.fields.bank_name'))->maxLength(100),
                TextInput::make('account_holder')->label(__('provider.fields.account_holder'))->maxLength(150),
                TextInput::make('iban')->label(__('provider.fields.iban'))->regex('/^SA\d{22}$/')->placeholder('SA0000000000000000000000'),
            ]),
        ]);
    }

    protected function getFormActions(): array
    {
        return $this->readOnly() ? [] : parent::getFormActions();
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        abort_if($this->readOnly(), 403);

        foreach (['logo', 'cover'] as $image) {
            $old = $record->getOriginal($image);
            if ($old && $old !== ($data[$image] ?? null) && ! str_starts_with($old, 'demo/')) {
                Storage::disk('public')->delete($old);
            }
        }

        $allowed = ['name_ar', 'name_en', 'bio_ar', 'bio_en', 'logo', 'cover', 'vat_number', 'phone', 'email', 'website', 'maps_url',
            'city_id', 'address', 'lat', 'lng', 'bank_name', 'account_holder', 'iban'];
        $record->update(array_intersect_key($data, array_flip($allowed)));

        return $record;
    }
}
