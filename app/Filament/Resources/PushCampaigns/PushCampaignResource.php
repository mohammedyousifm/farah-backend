<?php

namespace App\Filament\Resources\PushCampaigns;

use App\Filament\Concerns\ModuleResource;
use App\Filament\NavGroup;
use App\Filament\Resources\HomeSections\RelationManagers\ItemsRelationManager;
use App\Filament\Resources\PushCampaigns\Pages\CreatePushCampaign;
use App\Filament\Resources\PushCampaigns\Pages\EditPushCampaign;
use App\Filament\Resources\PushCampaigns\Pages\ListPushCampaigns;
use App\Filament\Support\Act;
use App\Filament\Support\Fmt;
use App\Models\PushCampaign;
use App\Services\CampaignService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Str;

/** Marketing push campaigns: compose, target, schedule or send now. */
class PushCampaignResource extends Resource
{
    use ModuleResource;

    protected static ?string $model = PushCampaign::class;

    protected static string $permissionModule = 'notifications';

    protected static string $langKey = 'push_campaigns';

    protected static string|\UnitEnum|null $navigationGroup = NavGroup::Content;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-megaphone';

    protected static ?int $navigationSort = 30;

    /** @return array<string, string> */
    public static function audiences(): array
    {
        return collect(CampaignService::AUDIENCES)->mapWithKeys(fn ($a) => [$a => __("admin.campaigns.audiences.{$a}")])->all();
    }

    /** @return array<string, string> */
    public static function statuses(): array
    {
        return collect(['draft', 'scheduled', 'sent'])->mapWithKeys(fn ($s) => [$s => __("admin.campaigns.statuses.{$s}")])->all();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('admin.campaigns.message'))->columns(2)->columnSpanFull()->schema([
                TextInput::make('title_ar')->label(__('admin.fields.title_ar'))->required()->maxLength(120),
                TextInput::make('title_en')->label(__('admin.fields.title_en'))->maxLength(120),
                Textarea::make('body_ar')->label(__('admin.fields.body_ar'))->required()->rows(3)->maxLength(500),
                Textarea::make('body_en')->label(__('admin.fields.body_en'))->rows(3)->maxLength(500),
            ]),
            Section::make(__('admin.campaigns.targeting'))->columns(2)->columnSpanFull()->schema([
                Select::make('audience')->label(__('admin.fields.audience'))->options(static::audiences())->required()->default('all')
                    ->helperText(__('admin.hints.campaign_audience')),
                DateTimePicker::make('scheduled_at')->label(__('admin.fields.scheduled_at'))->seconds(false)->minDate(now()->startOfDay())
                    ->helperText(__('admin.hints.campaign_schedule')),
                Select::make('link_type')->label(__('admin.fields.link_type'))->options(ItemsRelationManager::linkTypes())
                    ->required()->default('none')->live()->afterStateUpdated(fn (Set $set) => $set('link_value', null)),
                Select::make('link_value')->label(__('admin.fields.link_target'))->searchable()->required()
                    ->visible(fn (Get $get) => in_array($get('link_type'), ['venue', 'category', 'sector', 'provider'], true))
                    ->options(fn (Get $get) => ItemsRelationManager::linkOptions((string) $get('link_type'))),
                TextInput::make('link_value')->label(__('admin.fields.url'))->url()->rule('url:http,https')->required()->maxLength(255)
                    ->visible(fn (Get $get) => $get('link_type') === 'url'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('creator'))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make(Fmt::col('title'))->label(__('admin.fields.title'))->weight('bold')->searchable(['title_ar', 'title_en'])
                    ->description(fn (PushCampaign $r) => Str::limit($r->tr('body'), 60)),
                TextColumn::make('audience')->label(__('admin.fields.audience'))->badge()->color('gray')
                    ->formatStateUsing(fn ($state) => static::audiences()[$state] ?? $state),
                TextColumn::make('status')->label(__('admin.fields.status'))->badge()
                    ->formatStateUsing(fn ($state) => static::statuses()[$state] ?? $state)
                    ->color(fn ($state) => match ($state) {
                        'sent' => 'success',
                        'scheduled' => 'info',
                        default => 'gray',
                    }),
                TextColumn::make('scheduled_at')->label(__('admin.fields.scheduled_at'))->dateTime()->placeholder('-')->sortable(),
                TextColumn::make('sent_at')->label(__('admin.fields.sent_at'))->dateTime()->placeholder('-')->sortable(),
                TextColumn::make('recipients_count')->label(__('admin.fields.recipients_count'))->numeric()->badge()->color('info'),
                TextColumn::make('creator.name')->label(__('admin.fields.created_by'))->placeholder('-')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('admin.fields.status'))->options(static::statuses()),
                SelectFilter::make('audience')->label(__('admin.fields.audience'))->options(static::audiences()),
            ])
            ->recordActions([
                static::sendAction(),
                EditAction::make()->visible(fn (PushCampaign $r) => $r->status !== 'sent'),
                DeleteAction::make()->visible(fn (PushCampaign $r) => $r->status !== 'sent'),
            ]);
    }

    public static function sendAction(): Action
    {
        return Action::make('send_now')->label(__('admin.actions.send_now'))->icon('heroicon-o-paper-airplane')->color('primary')
            ->visible(fn (PushCampaign $record) => $record->status !== 'sent' && static::canManage())
            ->requiresConfirmation()
            ->modalDescription(fn (PushCampaign $record) => __('admin.campaigns.confirm_send', [
                'count' => number_format(app(CampaignService::class)->recipients($record)->count()),
            ]))
            ->action(function (PushCampaign $record) {
                $count = Act::run(fn () => app(CampaignService::class)->send($record), __('admin.campaigns.sent'));
                if ($count !== null) {
                    Notification::make()->info()->title(__('admin.campaigns.sent_to', ['count' => number_format($count)]))->send();
                }
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPushCampaigns::route('/'),
            'create' => CreatePushCampaign::route('/create'),
            'edit' => EditPushCampaign::route('/{record}/edit'),
        ];
    }
}
