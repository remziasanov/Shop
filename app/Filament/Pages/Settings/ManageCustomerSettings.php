<?php

declare(strict_types=1);

namespace App\Filament\Pages\Settings;

use App\Filament\Concerns\AdminOnlySettingsPage;
use App\Models\CustomerGroup;
use App\Services\Access\CustomerGroupService;
use App\Settings\CustomerSettings;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Pages\SettingsPage;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * Settings → «Покупатели и регистрация» (CA-23, CA-01, CG-11). The group that new customers get is not a setting of
 * its own: it is the `is_default_for_new` flag of a customer group, set here through {@see CustomerGroupService}.
 */
class ManageCustomerSettings extends SettingsPage
{
    use AdminOnlySettingsPage;

    protected static string $settings = CustomerSettings::class;

    protected static string $translationKey = 'customers';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static ?int $navigationSort = 70;

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('account.settings.sections.registration'))->schema([
                Toggle::make('registration_enabled')
                    ->label(__('account.settings.fields.registration_enabled'))
                    ->helperText(__('account.settings.hints.registration_enabled')),
                Toggle::make('allow_guest_checkout')
                    ->label(__('account.settings.fields.allow_guest_checkout'))
                    ->helperText(__('account.settings.hints.allow_guest_checkout')),
                Select::make('default_group_id')
                    ->label(__('account.settings.fields.default_group'))
                    ->helperText(__('account.settings.hints.default_group'))
                    ->options(fn (): array => CustomerGroup::query()->active()->where('code', '!=', CustomerGroup::GUEST)->ordered()->pluck('name', 'id')->all())
                    ->required()
                    ->native(false),
                TextInput::make('password_min_length')
                    ->label(__('account.settings.fields.password_min_length'))
                    ->numeric()
                    ->integer()
                    ->minValue(8)
                    ->maxValue(64)
                    ->required(),
            ])->columns(2),
            Section::make(__('account.settings.sections.codes'))->schema([
                TextInput::make('code_ttl_minutes')
                    ->label(__('account.settings.fields.code_ttl_minutes'))
                    ->helperText(__('account.settings.hints.code_ttl_minutes'))
                    ->numeric()->integer()->minValue(1)->maxValue(120)->required(),
                TextInput::make('code_max_attempts')
                    ->label(__('account.settings.fields.code_max_attempts'))
                    ->helperText(__('account.settings.hints.code_max_attempts'))
                    ->numeric()->integer()->minValue(1)->maxValue(10)->required(),
                TextInput::make('code_resend_seconds')
                    ->label(__('account.settings.fields.code_resend_seconds'))
                    ->helperText(__('account.settings.hints.code_resend_seconds'))
                    ->numeric()->integer()->minValue(10)->maxValue(600)->required(),
            ])->columns(3),
            Section::make(__('account.settings.sections.retention'))->schema([
                TextInput::make('unverified_retention_days')
                    ->label(__('account.settings.fields.unverified_retention_days'))
                    ->helperText(__('account.settings.hints.unverified_retention_days'))
                    ->numeric()->integer()->minValue(1)->maxValue(365)->required(),
                TextInput::make('deletion_grace_days')
                    ->label(__('account.settings.fields.deletion_grace_days'))
                    ->helperText(__('account.settings.hints.deletion_grace_days'))
                    ->numeric()->integer()->minValue(0)->maxValue(30)->required(),
            ])->columns(2),
            Section::make(__('account.settings.sections.mail'))->schema([
                Textarea::make('code_mail_warning')
                    ->label(__('account.settings.fields.code_mail_warning'))
                    ->required()->rows(2)->maxLength(500),
                Textarea::make('code_mail_footer')
                    ->label(__('account.settings.fields.code_mail_footer'))
                    ->helperText(__('account.settings.hints.code_mail_footer'))
                    ->rows(2)->maxLength(1000),
            ])->columns(1),
        ])->columns(1);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['default_group_id'] = CustomerGroup::defaultForNew()->id;

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $groupId = (int) ($data['default_group_id'] ?? 0);
        unset($data['default_group_id']);

        $group = $groupId > 0 ? CustomerGroup::query()->find($groupId) : null;

        if ($group !== null && ! $group->is_default_for_new) {
            app(CustomerGroupService::class)->setDefaultForNew($group);
        }

        $data['code_mail_footer'] = (string) ($data['code_mail_footer'] ?? '');

        return $data;
    }
}
