<?php

declare(strict_types=1);

namespace App\Filament\Resources\Customers\Schemas;

use App\Models\Customer;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Customer card (CA-20): account data, state (confirmation, block with the reason, deletion request, anonymization)
 * and the company requisites. The tabs of orders, addresses and wholesale applications are relation managers.
 */
final class CustomerInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make(__('customers.view.section_main'))
                    ->columns(['default' => 1, 'md' => 3])
                    ->schema([
                        TextEntry::make('name')->label(__('customers.view.name'))->weight('bold'),
                        TextEntry::make('email')
                            ->label(__('customers.view.email'))
                            ->copyable()
                            ->url(fn (Customer $record): ?string => $record->anonymized_at === null ? 'mailto:'.$record->email : null),
                        TextEntry::make('phone')
                            ->label(__('customers.view.phone'))
                            ->placeholder(__('customers.view.dash'))
                            ->url(fn (Customer $record): ?string => $record->phone === null ? null : 'tel:'.$record->phone),
                        TextEntry::make('group.name')->label(__('customers.view.group'))->badge()->color('gray'),
                        TextEntry::make('created_at')->label(__('customers.view.registered'))->dateTime('d.m.Y H:i'),
                        TextEntry::make('last_login_at')->label(__('customers.view.last_login'))->dateTime('d.m.Y H:i')->placeholder(__('customers.view.dash')),
                        TextEntry::make('uuid')->label(__('customers.view.uuid'))->copyable()->columnSpanFull(),
                    ]),

                Section::make(__('customers.view.section_status'))
                    ->columns(['default' => 1, 'md' => 3])
                    ->schema([
                        TextEntry::make('email_verified_at')
                            ->label(__('customers.view.verified_at'))
                            ->dateTime('d.m.Y H:i')
                            ->placeholder(__('customers.view.not_verified'))
                            ->color(fn (Customer $record): ?string => $record->email_verified_at === null ? 'warning' : null),
                        TextEntry::make('is_blocked')
                            ->label(__('customers.view.blocked'))
                            ->state(fn (Customer $record): string => $record->is_blocked ? __('customers.view.blocked_yes') : __('customers.view.blocked_no'))
                            ->badge()
                            ->color(fn (Customer $record): string => $record->is_blocked ? 'danger' : 'gray'),
                        TextEntry::make('blocked_reason')
                            ->label(__('customers.view.blocked_reason'))
                            ->placeholder(__('customers.view.dash'))
                            ->visible(fn (Customer $record): bool => $record->is_blocked),
                        TextEntry::make('deletion_requested_at')
                            ->label(__('customers.view.deletion_requested'))
                            ->dateTime('d.m.Y H:i')
                            ->placeholder(__('customers.view.deletion_none'))
                            ->color(fn (Customer $record): ?string => $record->deletion_requested_at !== null ? 'danger' : null),
                        TextEntry::make('anonymized_at')
                            ->label(__('customers.view.anonymized'))
                            ->dateTime('d.m.Y H:i')
                            ->placeholder(__('customers.view.anonymized_none')),
                        TextEntry::make('consent_at')
                            ->label(__('customers.view.consent'))
                            ->state(fn (Customer $record): string => $record->consent_at === null
                                ? __('customers.view.dash')
                                : __('customers.view.consent_value', [
                                    'time' => $record->consent_at->format('d.m.Y H:i'),
                                    'ip' => $record->consent_ip ?? __('customers.view.dash'),
                                ])),
                    ]),

                Section::make(__('customers.view.section_company'))
                    ->columns(['default' => 1, 'md' => 3])
                    ->schema([
                        TextEntry::make('company.name')->label(__('customers.view.company_name'))->placeholder(__('customers.view.company_none')),
                        TextEntry::make('company.inn')->label(__('customers.view.inn'))->placeholder(__('customers.view.dash'))->copyable(),
                        TextEntry::make('company.kpp')->label(__('customers.view.kpp'))->placeholder(__('customers.view.dash')),
                        TextEntry::make('company.ogrn')->label(__('customers.view.ogrn'))->placeholder(__('customers.view.dash')),
                        TextEntry::make('company.legal_address')->label(__('customers.view.legal_address'))->placeholder(__('customers.view.dash'))->columnSpan(['default' => 1, 'md' => 2]),
                    ]),
            ]);
    }
}
