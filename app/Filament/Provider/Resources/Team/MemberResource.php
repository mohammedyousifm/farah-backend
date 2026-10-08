<?php

namespace App\Filament\Provider\Resources\Team;

use App\Enums\UserStatus;
use App\Enums\UserType;
use App\Exceptions\BusinessException;
use App\Filament\Provider\Concerns\ProviderResource;
use App\Filament\Provider\ProviderNavGroup;
use App\Filament\Provider\Resources\Team\Pages\ManageMembers;
use App\Filament\Provider\Support\ProviderAccess;
use App\Filament\Support\Act;
use App\Filament\Support\Fmt;
use App\Models\ProviderMember;
use App\Models\User;
use App\Notifications\SystemNotification;
use App\Services\OtpService;
use Filament\Actions\Action;
use Filament\Auth\Notifications\ResetPassword as ResetPasswordNotification;
use Filament\Facades\Filament;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;

/**
 * Provider team (same rules as the provider API TeamController). Members sign in to
 * the web dashboard with their email + password; the owner either sets a temporary
 * password or sends a password-reset link.
 */
class MemberResource extends Resource
{
    use ProviderResource;

    protected static ?string $model = ProviderMember::class;

    protected static ?string $tenantRelationshipName = 'members';

    protected static string $area = 'team';

    protected static ?string $viewPermission = 'team.manage';

    protected static ?string $managePermission = 'team.manage';

    protected static string $langKey = 'team';

    protected static ?string $slug = 'team';

    protected static string|\UnitEnum|null $navigationGroup = ProviderNavGroup::Account;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-user-group';

    protected static ?int $navigationSort = 5;

    /** @return array<string, string> */
    public static function permissionOptions(): array
    {
        return collect(ProviderMember::PERMISSIONS)->mapWithKeys(fn ($p) => [$p => __('provider.permissions.'.str_replace('.', '_', $p))])->all();
    }

    /** @return array<string, string> */
    public static function roleOptions(): array
    {
        return ['manager' => __('provider.team.roles.manager'), 'staff' => __('provider.team.roles.staff')];
    }

    /** Role + permission inputs shared by invite and edit. */
    public static function accessComponents(): array
    {
        return [
            Select::make('role')->label(__('provider.fields.role'))->options(static::roleOptions())->required()->live()->default('staff')
                ->helperText(__('provider.team.role_hint')),
            CheckboxList::make('permissions')->label(__('provider.fields.permissions'))->options(static::permissionOptions())->columns(2)
                ->visible(fn (Get $get) => $get('role') === 'staff')->columnSpanFull(),
        ];
    }

    public static function inviteSchema(): array
    {
        return [
            TextInput::make('name')->label(__('provider.fields.name'))->required()->maxLength(120),
            TextInput::make('email')->label(__('provider.fields.email'))->email()->required()->maxLength(120),
            TextInput::make('phone')->label(__('provider.fields.phone'))->tel()->regex('/^(\+?966|0)?5\d{8}$/')->placeholder('05XXXXXXXX'),
            ...static::accessComponents(),
            Radio::make('access')->label(__('provider.team.access'))->required()->live()->default('reset_link')->columnSpanFull()
                ->options(['reset_link' => __('provider.team.access_reset_link'), 'password' => __('provider.team.access_password')]),
            TextInput::make('password')->label(__('provider.team.temporary_password'))->password()->revealable()->minLength(8)
                ->visible(fn (Get $get) => $get('access') === 'password')->required(fn (Get $get) => $get('access') === 'password')
                ->helperText(__('provider.team.temporary_password_hint')),
        ];
    }

    /** Invite (or re-activate) a member. Returns [member, notice]. */
    public static function invite(array $data): ProviderMember
    {
        $provider = ProviderAccess::provider();
        $email = strtolower(trim($data['email']));
        $phone = filled($data['phone'] ?? null) ? OtpService::normalize($data['phone']) : null;

        [$member, $user, $created] = DB::transaction(function () use ($provider, $data, $email, $phone) {
            $user = User::where('email', $email)->first() ?? ($phone ? User::where('phone', $phone)->first() : null);
            $created = false;

            if ($user && $user->type === UserType::Admin) {
                throw new BusinessException(__('provider.team.admin_account'), 'admin_account');
            }

            if (! $user) {
                $user = User::create([
                    'name' => $data['name'],
                    'email' => $email,
                    'phone' => $phone,
                    'type' => UserType::Customer,
                    'status' => UserStatus::Active,
                    'locale' => app()->getLocale(),
                ]);
                $created = true;
            }

            // Only a brand-new (or password-less) account gets the temporary password.
            if (($data['access'] ?? null) === 'password' && filled($data['password'] ?? null) && ($created || blank($user->password))) {
                $user->forceFill(['password' => $data['password']])->save();
            }

            $existing = $provider->members()->where('user_id', $user->id)->first();
            if ($existing?->is_active) {
                throw new BusinessException(__('api.providers.member_exists'), 'member_exists');
            }

            $attributes = [
                'role' => $data['role'],
                'permissions' => $data['role'] === 'staff' ? array_values((array) ($data['permissions'] ?? [])) : null,
                'is_active' => true,
            ];
            $member = $existing ? tap($existing)->update($attributes) : $provider->members()->create($attributes + ['user_id' => $user->id]);

            return [$member, $user, $created];
        });

        if (($data['access'] ?? null) === 'reset_link' && $user->email) {
            static::sendResetLink($user);
        }

        SystemNotification::send($user, 'team_invited', [
            'provider' => $provider->name_ar,
            'role' => __('api.roles.'.$member->role),
        ], ['provider_id' => $provider->id]);

        return $member;
    }

    public static function sendResetLink(User $user): void
    {
        Password::broker(Filament::getAuthPasswordBroker())->sendResetLink(['email' => $user->email],
            function (CanResetPassword $user, string $token): void {
                $notification = app(ResetPasswordNotification::class, ['token' => $token]);
                $notification->url = Filament::getResetPasswordUrl($token, $user);
                $user->notify($notification);
            });
    }

    /** Owners and yourself cannot be edited (same as the API). */
    public static function editable(ProviderMember $member): bool
    {
        return static::canManage() && $member->role !== 'owner' && $member->user_id !== auth()->id();
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('user'))
            ->defaultSort('id')
            ->columns([
                TextColumn::make('user.name')->label(__('provider.fields.name'))->weight('bold')
                    ->description(fn (ProviderMember $r) => $r->user?->email),
                TextColumn::make('user.phone')->label(__('provider.fields.phone'))->placeholder('-')->formatStateUsing(fn ($state) => Fmt::phone($state)),
                TextColumn::make('role')->label(__('provider.fields.role'))->badge()
                    ->formatStateUsing(fn ($state) => __("provider.team.roles.{$state}"))
                    ->color(fn ($state) => match ($state) {
                        'owner' => 'primary',
                        'manager' => 'info',
                        default => 'gray',
                    }),
                TextColumn::make('permissions')->label(__('provider.fields.permissions'))->badge()->color('gray')
                    ->state(fn (ProviderMember $r) => $r->role === 'staff'
                        ? collect($r->permissions ?? [])->map(fn ($p) => static::permissionOptions()[$p] ?? $p)->all()
                        : [__('provider.team.all_permissions')])
                    ->listWithLineBreaks()->limitList(3)->expandableLimitedList(),
                IconColumn::make('is_active')->label(__('provider.fields.is_active'))->boolean(),
                TextColumn::make('created_at')->label(__('provider.fields.created_at'))->date('Y-m-d'),
            ])
            ->recordActions([
                Action::make('edit_access')->label(__('provider.team.edit_access'))->icon('heroicon-o-pencil-square')
                    ->visible(fn (ProviderMember $r) => static::editable($r))
                    ->fillForm(fn (ProviderMember $r) => ['role' => $r->role, 'permissions' => $r->permissions ?? []])
                    ->schema(static::accessComponents())
                    ->action(fn (ProviderMember $record, array $data) => Act::run(fn () => $record->update([
                        'role' => $data['role'],
                        'permissions' => $data['role'] === 'staff' ? array_values((array) ($data['permissions'] ?? [])) : null,
                    ]), __('provider.saved'))),
                Action::make('send_reset')->label(__('provider.team.send_reset'))->icon('heroicon-o-envelope')->color('gray')
                    ->visible(fn (ProviderMember $r) => static::editable($r) && $r->is_active && filled($r->user?->email))
                    ->requiresConfirmation()
                    ->action(fn (ProviderMember $record) => Act::run(fn () => static::sendResetLink($record->user), __('provider.team.reset_sent'))),
                Action::make('deactivate')->label(__('provider.team.deactivate'))->icon('heroicon-o-no-symbol')->color('danger')
                    ->visible(fn (ProviderMember $r) => static::editable($r) && $r->is_active)
                    ->requiresConfirmation()
                    ->action(fn (ProviderMember $record) => Act::run(fn () => $record->update(['is_active' => false]), __('api.providers.member_deactivated'))),
                Action::make('activate')->label(__('provider.team.activate'))->icon('heroicon-o-check-circle')->color('success')
                    ->visible(fn (ProviderMember $r) => static::editable($r) && ! $r->is_active)
                    ->requiresConfirmation()
                    ->action(fn (ProviderMember $record) => Act::run(fn () => $record->update(['is_active' => true]), __('provider.saved'))),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageMembers::route('/')];
    }
}
